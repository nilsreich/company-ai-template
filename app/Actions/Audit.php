<?php

namespace App\Actions;

use App\Models\AuditEntry;
use App\Models\Task;
use App\Models\User;
use Spatie\Activitylog\Facades\Activity;

final class Audit
{
    /** @param array<string, mixed> $changes */
    public static function record(string $action, ?User $actor, ?Task $task, array $changes = []): AuditEntry
    {
        $log = Activity::useLog('tasks')->enableLogging()->event($action)->withProperties($changes);
        $actor ? $log->causedBy($actor) : $log->causedByAnonymous();
        if ($task) {
            $log->performedOn($task);
        }
        $entry = $log->log($action);
        if (! $entry instanceof AuditEntry || ! $entry->exists) {
            throw new \RuntimeException('Audit-Eintrag konnte nicht geschrieben werden.');
        }

        return $entry->refresh();
    }
}
