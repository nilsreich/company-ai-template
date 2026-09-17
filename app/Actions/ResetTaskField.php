<?php

namespace App\Actions;

use App\Contracts\ResultValidator;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ResetTaskField
{
    public function handle(User $actor, Task $task, int $revision, string $field): Task
    {
        return DB::transaction(function () use ($actor, $task, $revision, $field): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('update', $task);
            if ($task->revision !== $revision) {
                throw ValidationException::withMessages([$field => 'Die Aufgabe wurde inzwischen geändert. Bitte neu laden.']);
            }
            $execution = $task->originalExecution();
            abort_unless($execution && is_array($execution->result) && array_key_exists($field, $execution->result), 422, 'Kein ursprünglicher KI-Wert vorhanden.');
            $before = $task->payload();
            $values = app(ResultValidator::class)->handle([...$before, $field => $execution->result[$field]]);
            $task->update(['payload' => $values, 'revision' => $revision + 1, 'status' => TaskStatus::InReview]);
            Audit::record('field_reset', $actor, $task, ['field' => $field, 'source_execution_id' => $execution->id, 'before' => $before, 'after' => $task->payload(), 'revision_before' => $revision, 'revision_after' => $revision + 1]);

            return $task;
        });
    }
}
