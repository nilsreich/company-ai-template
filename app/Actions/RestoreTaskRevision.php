<?php

namespace App\Actions;

use App\Contracts\ResultValidator;
use App\Enums\TaskStatus;
use App\Models\AuditEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RestoreTaskRevision
{
    public function handle(User $actor, Task $task, int $revision, int $entryId): Task
    {
        return DB::transaction(function () use ($actor, $task, $revision, $entryId): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('update', $task);
            if ($task->revision !== $revision) {
                throw ValidationException::withMessages(['task' => 'Die Aufgabe wurde inzwischen geändert. Bitte neu laden.']);
            }
            $entry = AuditEntry::where('task_id', $task->id)->whereIn('action', ['corrected', 'revision_restored', 'field_reset', 'execution_completed'])->findOrFail($entryId);
            $changes = $entry->changes;
            abort_unless(is_array($changes) && isset($changes['after']) && is_array($changes['after']), 422, 'Historischer Stand ist unvollständig.');
            $values = app(ResultValidator::class)->handle($changes['after']);
            $before = $task->payload();
            $task->update(['payload' => $values, 'revision' => $revision + 1, 'status' => TaskStatus::InReview]);
            Audit::record('revision_restored', $actor, $task, ['source_entry_id' => $entry->id, 'before' => $before, 'after' => $task->payload(), 'revision_before' => $revision, 'revision_after' => $revision + 1]);

            return $task;
        });
    }
}
