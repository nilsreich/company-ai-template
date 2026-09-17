<?php

namespace Tests\Feature;

use App\Actions\SubmitFeedback;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feedback.repository' => 'example/private-feedback', 'feedback.token' => 'test-server-secret', 'feedback.build' => 'abc123', 'feedback.plan' => 'prototype']);
        Storage::fake('private');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'title' => 'Feedback fixture', 'description' => 'Beschreibung ohne Kundendaten', 'page' => 'tasks', 'consent' => '1'];
    }

    private function fakeGitHub(): void
    {
        Http::fake([
            'api.github.com/repos/example/private-feedback' => Http::response(['private' => true, 'has_issues' => true, 'archived' => false]),
            'api.github.com/repos/example/private-feedback/issues' => Http::response(['number' => 42], 201),
        ]);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('screenshot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aU8cAAAAASUVORK5CYII='));
    }

    public function test_widget_is_local_authenticated_and_never_exposes_credentials(): void
    {
        $this->app->instance('env', 'local');
        $this->get('/login')->assertOk()->assertDontSee('app-feedback');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Feedback geben')->assertDontSee('test-server-secret')->assertDontSee('marker.io');
        $this->app->instance('env', 'production');
        $this->get('/admin')->assertOk()->assertDontSee('app-feedback');
        $this->withSession(['_token' => 'feedback-csrf'])->postJson('/feedback', $this->payload() + ['_token' => 'feedback-csrf'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_feedback_is_sent_once_and_uses_server_metadata(): void
    {
        $this->fakeGitHub();
        $user = User::factory()->create(['name' => 'Editor', 'email' => 'editor@example.test']);
        $payload = $this->payload() + ['metadata' => ['reporter' => 'forged'], 'url' => 'https://secret.invalid/?token=hidden'];
        $this->actingAs($user)->postJson('/feedback', $payload)->assertOk()->assertJson(['status' => 'sent', 'issue_url' => 'https://github.com/example/private-feedback/issues/42']);
        $this->postJson('/feedback', $payload)->assertOk()->assertJson(['status' => 'sent']);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = $request['body'];
            $this->assertStringContainsString('editor@example.test', $body);
            $this->assertStringContainsString('abc123', $body);
            $this->assertStringNotContainsString('forged', $body);
            $this->assertStringNotContainsString('secret.invalid', $body);
            $this->assertSame(['title', 'body'], array_keys($request->data()));
            $this->assertFalse($request->isMultipart());

            return $request->hasHeader('Authorization', 'Bearer test-server-secret');
        });
        $this->assertDatabaseCount('feedback', 1);
    }

    public function test_screenshot_stays_private_and_only_active_admins_can_download(): void
    {
        $this->fakeGitHub();
        $user = User::factory()->create(['role' => Role::Editor]);
        $payload = $this->payload();
        $this->actingAs($user)->postJson('/feedback', $payload + ['screenshot' => $this->png()])->assertOk()->assertJson(['status' => 'sent']);
        $feedback = Feedback::findOrFail($payload['request_id']);
        Storage::disk('private')->assertExists($feedback->screenshot_path);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request['body'], '/feedback/'.$feedback->id.'/screenshot') && ! str_contains($request['body'], 'data:image'));
        $path = '/feedback/'.$feedback->id.'/screenshot';
        $this->get($path)->assertForbidden();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->get($path)->assertOk()->assertDownload('feedback-'.$feedback->id.'.png')->assertHeader('X-Content-Type-Options', 'nosniff');
        User::whereKey($admin->id)->update(['role' => Role::Editor]);
        $this->get($path)->assertForbidden();
        User::whereKey($admin->id)->update(['active' => false]);
        $this->get($path)->assertForbidden();
        $this->assertGuest();
        $this->get($path)->assertRedirect('/login');
    }

    public function test_public_repository_never_receives_feedback(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['private' => false, 'has_issues' => true, 'archived' => false])]);
        $this->actingAs(User::factory()->create())->postJson('/feedback', $this->payload() + ['screenshot' => $this->png()])->assertStatus(503);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertDatabaseCount('feedback', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('', 302, ['Location' => 'https://attacker.invalid']);
        });
        $this->actingAs(User::factory()->create())->postJson('/feedback', $this->payload())->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_missing_configuration_and_guests_cannot_send(): void
    {
        $this->postJson('/feedback', $this->payload())->assertUnauthorized();
        config(['feedback.token' => '']);
        $this->actingAs(User::factory()->create())->postJson('/feedback', $this->payload())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_direct_action_authorizes_a_fresh_active_user(): void
    {
        $user = User::factory()->create();
        User::whereKey($user->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(SubmitFeedback::class)->handle($user, $this->payload(), null);
    }

    public function test_consent_and_image_type_are_validated_before_any_external_request(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload();
        $payload['consent'] = '0';
        $this->actingAs($user)->postJson('/feedback', $payload)->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson('/feedback', $this->payload() + ['screenshot' => UploadedFile::fake()->createWithContent('fake.png', '<script>bad</script>')])->assertUnprocessable()->assertJsonValidationErrors('screenshot');
        Http::assertNothingSent();
    }

    public function test_unknown_delivery_is_retained_and_never_retried(): void
    {
        Http::fake([
            'api.github.com/repos/example/private-feedback' => Http::response(['private' => true, 'has_issues' => true, 'archived' => false]),
            'api.github.com/repos/example/private-feedback/issues' => Http::failedConnection(),
        ]);
        $payload = $this->payload();
        $this->actingAs(User::factory()->create())->postJson('/feedback', $payload)->assertOk()->assertJson(['status' => 'uncertain']);
        $this->postJson('/feedback', $payload)->assertOk()->assertJson(['status' => 'uncertain']);
        $this->assertSame('uncertain', Feedback::findOrFail($payload['request_id'])->status);
        Http::assertSentCount(2);
    }

    public function test_provider_errors_do_not_expose_the_response_body(): void
    {
        Http::fake([
            'api.github.com/repos/example/private-feedback' => Http::response(['private' => true, 'has_issues' => true, 'archived' => false]),
            'api.github.com/repos/example/private-feedback/issues' => Http::response(['message' => 'sensitive-provider-content'], 403),
        ]);
        $this->actingAs(User::factory()->create())->postJson('/feedback', $this->payload())->assertOk()->assertJson(['status' => 'failed'])->assertDontSee('sensitive-provider-content');
    }

    public function test_another_user_cannot_reuse_a_submission_id(): void
    {
        $this->fakeGitHub();
        $payload = $this->payload();
        $this->actingAs(User::factory()->create())->postJson('/feedback', $payload)->assertOk();
        $this->actingAs(User::factory()->create())->postJson('/feedback', $payload)->assertForbidden();
        Http::assertSentCount(2);
    }
}
