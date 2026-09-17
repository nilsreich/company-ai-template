<?php

namespace App\Actions;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ExportTask
{
    public function handle(User $actor, Task $task): string
    {
        $task->refresh();
        Gate::forUser($actor)->authorize('export', $task);
        $json = json_encode([
            'task_id' => $task->id, 'title' => $task->title, 'status' => $task->status->value,
            'revision' => $task->revision, 'approved_at' => $task->approved_at?->toIso8601String(),
            'payload' => $task->payload(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        Audit::record('exported', $actor, $task, ['revision' => $task->revision, 'sha256' => hash('sha256', $json), 'payload' => $task->payload()]);

        return $json;
    }
}
