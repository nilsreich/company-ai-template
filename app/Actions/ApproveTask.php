<?php

namespace App\Actions;

use App\Contracts\ResultValidator;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApproveTask
{
    public function __construct(private ResultValidator $validate) {}

    public function handle(User $actor, Task $task, int $revision): void
    {
        DB::transaction(function () use ($actor, $task, $revision): void {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('approve', $task);
            if ($task->revision !== $revision) {
                throw ValidationException::withMessages(['task' => 'Geänderte Aufgabe bitte erneut prüfen.']);
            }
            $this->validate->handle($task->payload());
            $task->update(['status' => TaskStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'revision' => $revision + 1]);
            Audit::record('approved', $actor, $task, ['revision_before' => $revision, 'revision_after' => $revision + 1, 'payload' => $task->payload()]);
        });
    }
}
