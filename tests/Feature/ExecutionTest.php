<?php

namespace Tests\Feature;

use App\Actions\ApproveTask;
use App\Actions\CorrectTask;
use App\Actions\ProcessExecution;
use App\Actions\StartExecution;
use App\Actions\UploadTask;
use App\Ai\TaskExtractor;
use App\Ai\TaskResult;
use App\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Jobs\RunExecution;
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

class ExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_execution_is_idempotent(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $execution = $task->executions()->sole();
        $job = new RunExecution($execution->id);
        $job->handle(app(ProcessExecution::class));
        $job->handle(app(ProcessExecution::class));
        $this->assertSame(1, $task->refresh()->revision);
        $this->assertSame(1, $execution->refresh()->attempts);
        $this->assertSame(ExecutionStatus::Succeeded, $execution->status);
    }

    public function test_active_lease_prevents_parallel_execution(): void
    {
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        $execution->update(['status' => ExecutionStatus::Running, 'lease_owner' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame(0, $execution->refresh()->attempts);
        $this->assertSame(TaskStatus::Draft, $task->refresh()->status);
    }

    public function test_rate_limit_retries_then_succeeds(): void
    {
        config(['ai.fake_scenario' => 'rate_limit']);
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        $this->assertSame(10, app(ProcessExecution::class)->handle($execution->id));
        $this->assertSame(ExecutionStatus::Queued, $execution->refresh()->status);
        $this->travel(11)->seconds();
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame(ExecutionStatus::Succeeded, $execution->refresh()->status);
        $this->assertSame(2, $execution->attempts);
    }

    public function test_sdk_rate_limit_is_retried_only_by_the_job_and_applied_once(): void
    {
        config(['ai.driver' => 'live', 'ai.key' => 'fixture-only']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push([], 429)->push([
            'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode([...$this->taskPayload(), 'confidence' => ['summary' => 0.9, 'excerpt' => 0.8, 'language' => 1.0]])]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
        ])]);
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        $this->assertSame(10, app(ProcessExecution::class)->handle($execution->id));
        Http::assertSentCount(1);
        $this->travel(11)->seconds();
        app(ProcessExecution::class)->handle($execution->id);
        app(ProcessExecution::class)->handle($execution->id);
        Http::assertSentCount(2);
        $this->assertSame(ExecutionStatus::Succeeded, $execution->refresh()->status);
        $this->assertSame(2, $execution->attempts);
        $this->assertEquals($this->taskPayload(), $execution->result);
        $this->assertSame(30, $execution->usage['total_tokens']);
        $this->assertSame(TaskStatus::InReview, $task->refresh()->status);
        $this->assertSame(1, $task->revision);
    }

    public function test_timeout_exhausts_budget_and_manual_retry_creates_new_history(): void
    {
        config(['ai.fake_scenario' => 'timeout']);
        $user = User::factory()->create();
        $task = $this->upload($user);
        $execution = $task->executions()->sole();
        for ($i = 0; $i < 4; $i++) {
            app(ProcessExecution::class)->handle($execution->id);
            $this->travel(31)->seconds();
        }
        $this->assertSame(ExecutionStatus::Failed, $execution->refresh()->status);
        $this->assertSame(3, $execution->attempts);
        config(['ai.fake_scenario' => 'success']);
        $new = app(StartExecution::class)->handle($user, $task);
        $this->assertNotSame($execution->id, $new->id);
        $this->assertSame(2, $task->executions()->count());
    }

    public function test_invalid_result_is_not_retried(): void
    {
        config(['ai.fake_scenario' => 'invalid']);
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame('invalid_result', $execution->refresh()->error_category);
        $this->assertSame(1, $execution->attempts);
        $this->assertSame(TaskStatus::Draft, $task->refresh()->status);
    }

    public function test_late_response_preserves_human_correction(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $execution = $task->executions()->sole();
        $this->mock(TaskExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($user, $task): TaskResult {
            $this->assertSame(1, DB::transactionLevel()); // Only the test's outer transaction remains.
            app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload());

            return new TaskResult([...$this->taskPayload(), 'summary' => 'Late AI']);
        });
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame('Korrigierte Zusammenfassung', $task->refresh()->payload()['summary']);
        $this->assertFalse($execution->refresh()->applied);
        $this->assertSame('Late AI', $execution->result['summary']);
    }

    public function test_expired_worker_lease_can_be_reclaimed(): void
    {
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        $execution->update(['status' => ExecutionStatus::Running, 'attempts' => 1, 'lease_owner' => (string) Str::uuid(), 'lease_until' => now()->subSecond()]);
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame(ExecutionStatus::Succeeded, $execution->refresh()->status);
        $this->assertSame(2, $execution->attempts);
    }

    public function test_missing_dispatch_is_recovered(): void
    {
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        $execution->update(['dispatched_at' => null]);
        Queue::fake();
        $this->artisan('ai:recover')->assertSuccessful();
        Queue::assertPushed(RunExecution::class, fn ($job) => $job->executionId === $execution->id);
    }

    public function test_changed_input_version_blocks_result_application(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $execution = $task->executions()->sole();
        $this->mock(TaskExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($task): TaskResult {
            $task->update(['input_version' => 2]);

            return new TaskResult($this->taskPayload());
        });
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertFalse($execution->refresh()->applied);
        $this->assertSame(TaskStatus::Draft, $task->refresh()->status);
    }

    public function test_approval_during_execution_remains_immutable(): void
    {
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $task = $this->upload($user);
        $execution = $task->executions()->sole();
        $this->mock(TaskExtractor::class)->shouldReceive('extract')->once()->andReturnUsing(function () use ($user, $task): TaskResult {
            $corrected = app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload());
            app(ApproveTask::class)->handle($user, $corrected, $corrected->revision);

            return new TaskResult([...$this->taskPayload(), 'summary' => 'Too late']);
        });
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame(TaskStatus::Approved, $task->refresh()->status);
        $this->assertSame('Korrigierte Zusammenfassung', $task->payload()['summary']);
        $this->assertFalse($execution->refresh()->applied);
    }

    public function test_exhausted_interrupted_execution_is_terminal(): void
    {
        $task = $this->upload(User::factory()->create());
        $execution = $task->executions()->sole();
        $execution->update(['attempts' => 3, 'status' => ExecutionStatus::Running, 'lease_until' => now()->subSecond()]);
        app(ProcessExecution::class)->handle($execution->id);
        $this->assertSame(ExecutionStatus::Failed, $execution->refresh()->status);
        $this->assertSame('worker_interrupted', $execution->error_category);
    }

    public function test_deactivated_actor_cannot_retry_failed_execution(): void
    {
        config(['ai.fake_scenario' => 'invalid']);
        $user = User::factory()->create();
        $task = $this->upload($user);
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        User::whereKey($user->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(StartExecution::class)->handle($user, $task);
    }

    public static function invalidFiles(): array
    {
        return [['bad.pdf', 'text'], ['bad.txt', "\xff"], ['bad.txt', "a\0b"], ['empty.txt', ' '], ['big.txt', str_repeat('x', 262145)]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_uploads_are_rejected(string $name, string $text): void
    {
        $this->expectException(ValidationException::class);
        app(UploadTask::class)->handle(User::factory()->create(), UploadedFile::fake()->createWithContent($name, $text));
    }
}
