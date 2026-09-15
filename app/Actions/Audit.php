<?php

namespace App\Actions;

use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\User;

final class Audit
{
    /** @param array<string, mixed> $changes */
    public static function record(string $action, ?User $actor, ?Document $document, array $changes = []): void
    {
        AuditEntry::create(['action' => $action, 'user_id' => $actor?->id, 'document_id' => $document?->id, 'changes' => $changes, 'created_at' => now()]);
    }
}
