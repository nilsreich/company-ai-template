<?php

namespace App\Actions;

use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\User;
use Spatie\Activitylog\Facades\Activity;

final class Audit
{
    /** @param array<string, mixed> $changes */
    public static function record(string $action, ?User $actor, ?Document $document, array $changes = []): AuditEntry
    {
        $log = Activity::useLog('documents')->enableLogging()->event($action)->withProperties($changes);
        $actor ? $log->causedBy($actor) : $log->causedByAnonymous();
        if ($document) {
            $log->performedOn($document);
        }
        $entry = $log->log($action);
        if (! $entry instanceof AuditEntry || ! $entry->exists) {
            throw new \RuntimeException('Audit-Eintrag konnte nicht geschrieben werden.');
        }

        return $entry->refresh();
    }
}
