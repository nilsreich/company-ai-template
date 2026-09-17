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
    public function handle(User $actor, Task $task, int $revision, array $payload, ?string $title = null): Task
    {
        return DB::transaction(function () use ($actor, $task, $revision, $payload, $title): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('update', $task);
            if ($task->revision !== $revision) {
                throw ValidationException::withMessages(['payload' => 'Die Aufgabe wurde inzwischen geändert. Bitte neu laden.']);
            }
            $values = $this->validate->handle($payload);
            $before = $task->payload();
            $titleChanged = null;
            $updates = ['payload' => $values, 'revision' => $revision + 1, 'status' => TaskStatus::InReview];
            if (is_string($title)) {
                $trimmed = trim($title);
                if ($trimmed === '' || mb_strlen($trimmed) > 255) {
                    throw ValidationException::withMessages(['title' => 'Der Titel benötigt 1 bis 255 Zeichen.']);
                }
                $titleChanged = $trimmed === $task->title ? null : $trimmed;
                $updates['title'] = $trimmed;
            }
            $task->update($updates);
            Audit::record('corrected', $actor, $task, ['before' => $before, 'after' => $task->payload(), 'revision_before' => $revision, 'revision_after' => $revision + 1, 'title_changed' => $titleChanged]);

            return $task;
        });
    }
}
