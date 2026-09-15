<?php

namespace Tests\Feature;

use App\Actions\CorrectDocument;
use App\Actions\UpdateUserAccess;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['editor', false, false], ['reviewer', true, false], ['admin', true, true]];
    }

    #[DataProvider('roles')]
    public function test_role_permissions(string $role, bool $review, bool $admin): void
    {
        $user = User::factory()->create(['role' => Role::from($role)]);
        $document = $this->upload($user);
        $gate = Gate::forUser($user);
        foreach (['view', 'download', 'update'] as $ability) {
            $this->assertTrue($gate->allows($ability, $document));
        }
        $this->assertFalse($gate->allows('export', $document));
        $document->update(['status' => DocumentStatus::InReview]);
        $this->assertSame($review, $gate->allows('approve', $document));
        $this->assertSame($admin, $gate->allows('viewAny', User::class));
        $document->update(['status' => DocumentStatus::Approved]);
        $this->assertSame($review, $gate->allows('export', $document));
        $this->assertFalse($gate->allows('update', $document));
    }

    public function test_private_endpoints_require_auth_and_export_requires_approval_and_role(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        foreach (['download', 'export', 'status'] as $route) {
            $this->get(route('documents.'.$route, $document))->assertRedirect('/login');
        }
        $this->actingAs($user)->get(route('documents.export', $document))->assertForbidden();
        $this->get('/storage/'.$document->path)->assertNotFound();
        $this->get('/admin/users')->assertForbidden();
        Livewire::test(ViewDocument::class, ['record' => $document->id])->assertActionHidden('approve')->call('mountAction', 'approve')->call('callMountedAction');
        $this->assertSame(DocumentStatus::Draft, $document->refresh()->status);
    }

    public function test_deactivated_existing_session_is_rejected(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $this->actingAs($user)->get(route('documents.status', $document))->assertOk();
        User::whereKey($user->id)->update(['active' => false]);
        $this->get(route('documents.download', $document))->assertForbidden();
        $this->assertGuest();
    }

    public function test_role_change_is_effective_on_existing_livewire_session(): void
    {
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $document = $this->upload($user);
        $document = app(CorrectDocument::class)->handle($user, $document, 0, $this->fields());
        $page = Livewire::actingAs($user)->test(ViewDocument::class, ['record' => $document->id]);
        User::whereKey($user->id)->update(['role' => Role::Editor->value]);
        $page->call('mountAction', 'approve')->call('callMountedAction');
        $this->assertSame(DocumentStatus::InReview, $document->refresh()->status);
    }

    public function test_development_login_is_unreachable_in_production_even_with_flag(): void
    {
        config(['development.login' => true]);
        $this->app->instance('env', 'production');
        $user = User::factory()->create(['is_demo' => true]);
        $this->withSession(['_token' => 'fixture-csrf'])->post('/auth/development', ['user_id' => $user->id, '_token' => 'fixture-csrf'])->assertNotFound();
        $this->assertGuest();
        $this->get('/login')->assertDontSee('Lokale Entwicklungsanmeldung');
    }

    public function test_local_login_requires_flag_and_demo_user(): void
    {
        $user = User::factory()->create();
        config(['development.login' => false]);
        $this->post('/auth/development', ['user_id' => $user->id])->assertNotFound();
        config(['development.login' => true]);
        $this->post('/auth/development', ['user_id' => $user->id])->assertNotFound();
        $user->forceFill(['is_demo' => true])->save();
        $this->post('/auth/development', ['user_id' => $user->id])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    public function test_last_administrator_cannot_be_disabled(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->expectException(ValidationException::class);
        app(UpdateUserAccess::class)->handle($admin, $admin, Role::Editor, false);
    }
}
