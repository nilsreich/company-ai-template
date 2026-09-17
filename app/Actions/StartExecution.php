<?php

namespace App\Actions;

use App\Enums\ExecutionStatus;
use App\Jobs\RunExecution;
use App\Models\Execution;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final class StartExecution
{
    public function handle(User $actor, Task $task, bool $retry = true): Execution
    {
        return DB::transaction(function () use ($actor, $task, $retry): Execution {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize($retry ? 'retry' : 'update', $task);
            abort_if($task->executions()->whereIn('status', [ExecutionStatus::Queued, ExecutionStatus::Running])->exists(), 409, 'Verarbeitung läuft bereits.');
            abort_if(! $retry && $task->executions()->exists(), 409);
            $driver = config()->string('ai.driver');
            $provider = $driver === 'fake' ? 'fake' : 'live:'.config()->string('ai.live_provider', 'openai');
            $execution = $task->executions()->create([
                'input_version' => $task->input_version, 'task_revision' => $task->revision,
                'provider' => $provider, 'model' => $driver === 'fake' ? 'deterministic-v1' : config()->string('ai.model'),
                'prompt_version' => config()->string('ai.prompt_version'), 'fake_scenario' => config()->string('ai.fake_scenario'),
                'available_at' => now(), 'status' => ExecutionStatus::Queued,
            ]);
            DB::afterCommit(fn () => $this->dispatch($execution));
            Audit::record('execution_requested', $actor, $task, ['execution_id' => $execution->id]);

            return $execution;
        });
    }

    public function dispatch(Execution $execution): void
    {
        try {
            RunExecution::dispatch($execution->id)->afterCommit();
            $execution->update(['dispatched_at' => now()]);
        } catch (\Throwable) {
            // The committed execution is a durable dispatch intent; recovery re-enqueues it.
            Log::error('AI dispatch unavailable', ['execution_id' => $execution->id]);
        }
    }
}
