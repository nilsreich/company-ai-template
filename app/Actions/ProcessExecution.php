<?php

namespace App\Actions;

use App\Ai\FakeTaskExtractor;
use App\Ai\LiveTaskExtractor;
use App\Ai\TaskExtractor;
use App\Ai\TaskFailure;
use App\Ai\TaskInput;
use App\Contracts\ResultValidator;
use App\Enums\ExecutionStatus;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcessExecution
{
    public function __construct(private ResultValidator $validate, private TaskExtractor $extractor) {}

    /** Returns a delay for a retry, or null for an acknowledged job. */
    public function handle(int $id): ?int
    {
        $owner = (string) Str::uuid();
        $execution = DB::transaction(function () use ($id, $owner): ?Execution {
            $execution = Execution::query()->lockForUpdate()->find($id);
            if (! $execution || in_array($execution->status, [ExecutionStatus::Succeeded, ExecutionStatus::Failed], true)) {
                return null;
            }
            if ($execution->lease_until?->isFuture() || $execution->available_at?->isFuture()) {
                return null;
            }
            if ($execution->attempts >= config()->integer('ai.max_attempts')) {
                $execution->update(['status' => ExecutionStatus::Failed, 'error_category' => 'worker_interrupted', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);

                Audit::record('execution_failed', null, $execution->task, ['execution_id' => $execution->id, 'category' => 'worker_interrupted']);

                return null;
            }
            $execution->update(['status' => ExecutionStatus::Running, 'attempts' => $execution->attempts + 1, 'started_at' => now(), 'lease_owner' => $owner, 'lease_until' => now()->addSeconds(config()->integer('ai.lease_seconds')), 'error_category' => null]);

            Audit::record('execution_started', null, $execution->task, ['execution_id' => $execution->id, 'attempt' => $execution->attempts, 'model' => $execution->model, 'prompt_version' => $execution->prompt_version]);

            return $execution;
        });
        if (! $execution) {
            return null;
        }
        try {
            $task = $execution->task()->firstOrFail();
            if ($task->input_version !== $execution->input_version) {
                throw new TaskFailure('stale_input');
            }
            $text = Storage::disk('private')->get($task->path);
            if (! is_string($text)) {
                throw new TaskFailure('input_missing');
            }
            if (hash('sha256', $text) !== $task->sha256) {
                throw new TaskFailure('input_integrity');
            }
            // Bind by the persisted driver so configuration changes do not reroute old inputs.
            // Legacy executions store 'live'; new executions store 'live:<provider>'.
            $extractor = $this->resolveExtractor($execution->provider);
            $result = $extractor->extract(new TaskInput($text, $execution->attempts, $execution->model, $execution->prompt_version, $execution->fake_scenario, $task->mime_type));
            $payload = $this->validate->handle($result->payload);
            DB::transaction(function () use ($execution, $owner, $payload, $result): void {
                $task = Task::query()->lockForUpdate()->findOrFail($execution->task_id);
                $current = Execution::query()->lockForUpdate()->findOrFail($execution->id);
                if ($current->lease_owner !== $owner || ! $current->lease_until?->isFuture() || $current->status !== ExecutionStatus::Running) {
                    return;
                }
                $apply = $task->input_version === $execution->input_version && $task->revision === $execution->task_revision && $task->status !== TaskStatus::Approved;
                $before = $task->payload();
                $revision = $task->revision;
                if ($apply) {
                    $task->update(['payload' => $payload, 'revision' => $task->revision + 1, 'status' => TaskStatus::InReview]);
                }
                $current->update(['status' => ExecutionStatus::Succeeded, 'result' => $payload, 'confidence' => $result->confidence, 'usage' => $result->usage, 'applied' => $apply, 'error_category' => $apply ? null : 'superseded', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);
                Audit::record('execution_completed', null, $task, ['execution_id' => $current->id, 'applied' => $apply, 'before' => $before, 'after' => $task->payload(), 'result' => $payload, 'confidence' => $result->confidence, 'revision_before' => $revision, 'revision_after' => $task->revision]);
            });

            return null;
        } catch (\Throwable $e) {
            $category = $e instanceof TaskFailure ? $e->category : ($e instanceof ValidationException ? 'invalid_result' : 'internal_error');
            $retry = $e instanceof TaskFailure && $e->retryable && $execution->attempts < config()->integer('ai.max_attempts');
            $delay = $execution->attempts === 1 ? 10 : 30;
            $changed = DB::transaction(function () use ($execution, $owner, $retry, $delay, $category): int {
                $changed = Execution::whereKey($execution->id)->where('lease_owner', $owner)->where('status', ExecutionStatus::Running)->update([
                    'status' => $retry ? ExecutionStatus::Queued->value : ExecutionStatus::Failed->value, 'error_category' => $category,
                    'available_at' => now()->addSeconds($delay), 'finished_at' => $retry ? null : now(), 'lease_owner' => null, 'lease_until' => null,
                ]);
                if ($changed) {
                    Audit::record($retry ? 'execution_retry_scheduled' : 'execution_failed', null, $execution->task, ['execution_id' => $execution->id, 'attempt' => $execution->attempts, 'category' => $category]);
                }

                return $changed;
            });

            return $retry && $changed ? $delay : null;
        }
    }

    private function resolveExtractor(string $provider): TaskExtractor
    {
        $driver = config()->string('ai.driver');
        $currentLive = 'live:'.config()->string('ai.live_provider', 'openai');
        if (($provider === 'fake' && $driver === 'fake')
            || (($provider === 'live' || $provider === $currentLive) && $driver === 'live')) {
            return $this->extractor;
        }

        return match (true) {
            $provider === 'fake' => app(FakeTaskExtractor::class),
            $provider === 'live' || str_starts_with($provider, 'live:') => app(LiveTaskExtractor::class),
            default => throw new TaskFailure('configuration'),
        };
    }
}
