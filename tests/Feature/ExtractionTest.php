<?php

namespace Tests\Feature;

use App\Actions\ApproveDocument;
use App\Actions\CorrectDocument;
use App\Actions\ProcessExtraction;
use App\Actions\StartExtraction;
use App\Actions\UploadDocument;
use App\Ai\DocumentExtractor;
use App\Ai\ExtractionResult;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Jobs\ExtractDocument;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_execution_is_idempotent(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $run = $document->runs()->sole();
        $job = new ExtractDocument($run->id);
        $job->handle(app(ProcessExtraction::class));
        $job->handle(app(ProcessExtraction::class));
        $this->assertSame(1, $document->refresh()->revision);
        $this->assertSame(1, $run->refresh()->attempts);
        $this->assertSame(RunStatus::Succeeded, $run->status);
    }

    public function test_active_lease_prevents_parallel_execution(): void
    {
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        $run->update(['status' => RunStatus::Running, 'lease_owner' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame(0, $run->refresh()->attempts);
        $this->assertSame(DocumentStatus::Draft, $document->refresh()->status);
    }

    public function test_rate_limit_retries_then_succeeds(): void
    {
        config(['ai.fake_scenario' => 'rate_limit']);
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        $this->assertSame(10, app(ProcessExtraction::class)->handle($run->id));
        $this->assertSame(RunStatus::Queued, $run->refresh()->status);
        $this->travel(11)->seconds();
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame(RunStatus::Succeeded, $run->refresh()->status);
        $this->assertSame(2, $run->attempts);
    }

    public function test_sdk_rate_limit_is_retried_only_by_the_job_and_applied_once(): void
    {
        config(['ai.driver' => 'live', 'ai.key' => 'fixture-only']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push([], 429)->push([
            'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($this->fields())]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
        ])]);
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        $this->assertSame(10, app(ProcessExtraction::class)->handle($run->id));
        Http::assertSentCount(1);
        $this->travel(11)->seconds();
        app(ProcessExtraction::class)->handle($run->id);
        app(ProcessExtraction::class)->handle($run->id);
        Http::assertSentCount(2);
        $this->assertSame(RunStatus::Succeeded, $run->refresh()->status);
        $this->assertSame(2, $run->attempts);
        $this->assertEquals($this->fields(), $run->result);
        $this->assertSame(30, $run->usage['total_tokens']);
        $this->assertSame(DocumentStatus::InReview, $document->refresh()->status);
        $this->assertSame(1, $document->revision);
    }

    public function test_timeout_exhausts_budget_and_manual_retry_creates_new_history(): void
    {
        config(['ai.fake_scenario' => 'timeout']);
        $user = User::factory()->create();
        $document = $this->upload($user);
        $run = $document->runs()->sole();
        for ($i = 0; $i < 4; $i++) {
            app(ProcessExtraction::class)->handle($run->id);
            $this->travel(31)->seconds();
        }
        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
        $this->assertSame(3, $run->attempts);
        config(['ai.fake_scenario' => 'success']);
        $new = app(StartExtraction::class)->handle($user, $document);
        $this->assertNotSame($run->id, $new->id);
        $this->assertSame(2, $document->runs()->count());
    }

    public function test_invalid_result_is_not_retried(): void
    {
        config(['ai.fake_scenario' => 'invalid']);
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame('invalid_result', $run->refresh()->error_category);
        $this->assertSame(1, $run->attempts);
        $this->assertSame(DocumentStatus::Draft, $document->refresh()->status);
    }

    public function test_late_response_preserves_human_correction(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $run = $document->runs()->sole();
        $this->mock(DocumentExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($user, $document): ExtractionResult {
            $this->assertSame(1, DB::transactionLevel()); // Only the test's outer transaction remains.
            app(CorrectDocument::class)->handle($user, $document, 0, $this->fields());

            return new ExtractionResult([...$this->fields(), 'supplier' => 'Late AI']);
        });
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame('Korrigiert GmbH', $document->refresh()->supplier);
        $this->assertFalse($run->refresh()->applied);
        $this->assertSame('Late AI', $run->result['supplier']);
    }

    public function test_expired_worker_lease_can_be_reclaimed(): void
    {
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        $run->update(['status' => RunStatus::Running, 'attempts' => 1, 'lease_owner' => (string) Str::uuid(), 'lease_until' => now()->subSecond()]);
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame(RunStatus::Succeeded, $run->refresh()->status);
        $this->assertSame(2, $run->attempts);
    }

    public function test_missing_dispatch_is_recovered(): void
    {
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        $run->update(['dispatched_at' => null]);
        Queue::fake();
        $this->artisan('ai:recover')->assertSuccessful();
        Queue::assertPushed(ExtractDocument::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_changed_input_version_blocks_result_application(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $run = $document->runs()->sole();
        $this->mock(DocumentExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($document): ExtractionResult {
            $document->update(['input_version' => 2]);

            return new ExtractionResult($this->fields());
        });
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertFalse($run->refresh()->applied);
        $this->assertSame(DocumentStatus::Draft, $document->refresh()->status);
    }

    public function test_approval_during_extraction_remains_immutable(): void
    {
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $document = $this->upload($user);
        $run = $document->runs()->sole();
        $this->mock(DocumentExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($user, $document): ExtractionResult {
            $corrected = app(CorrectDocument::class)->handle($user, $document, 0, $this->fields());
            app(ApproveDocument::class)->handle($user, $corrected, $corrected->revision);

            return new ExtractionResult([...$this->fields(), 'supplier' => 'Too late']);
        });
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame(DocumentStatus::Approved, $document->refresh()->status);
        $this->assertSame('Korrigiert GmbH', $document->supplier);
        $this->assertFalse($run->refresh()->applied);
    }

    public function test_exhausted_interrupted_run_is_terminal(): void
    {
        $document = $this->upload(User::factory()->create());
        $run = $document->runs()->sole();
        $run->update(['attempts' => 3, 'status' => RunStatus::Running, 'lease_until' => now()->subSecond()]);
        app(ProcessExtraction::class)->handle($run->id);
        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
        $this->assertSame('worker_interrupted', $run->error_category);
    }

    public function test_deactivated_actor_cannot_retry_failed_run(): void
    {
        config(['ai.fake_scenario' => 'invalid']);
        $user = User::factory()->create();
        $document = $this->upload($user);
        app(ProcessExtraction::class)->handle($document->runs()->sole()->id);
        User::whereKey($user->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(StartExtraction::class)->handle($user, $document);
    }

    public static function invalidFiles(): array
    {
        return [['bad.pdf', 'text'], ['bad.txt', "\xff"], ['bad.txt', "a\0b"], ['empty.txt', ' '], ['big.txt', str_repeat('x', 262145)]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_uploads_are_rejected(string $name, string $text): void
    {
        $this->expectException(ValidationException::class);
        app(UploadDocument::class)->handle(User::factory()->create(), UploadedFile::fake()->createWithContent($name, $text));
    }
}
