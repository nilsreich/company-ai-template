<?php

namespace App\Actions;

use App\Contracts\ResultValidator;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CorrectTask
{
    public function __construct(private ResultValidator $validate) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, Task $task, int $revision, array $payload): Task
    {
        return DB::transaction(function () use ($actor, $task, $revision, $payload): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('update', $task);
            if ($task->revision !== $revision) {
                throw ValidationException::withMessages(['payload' => 'Die Aufgabe wurde inzwischen geändert. Bitte neu laden.']);
            }
            $values = $this->validate->handle($payload);
            $before = $task->payload();
            $task->update(['payload' => $values, 'revision' => $revision + 1, 'status' => TaskStatus::InReview]);
            Audit::record('corrected', $actor, $task, ['before' => $before, 'after' => $task->payload(), 'revision_before' => $revision, 'revision_after' => $revision + 1]);

            return $task;
        });
    }
}
