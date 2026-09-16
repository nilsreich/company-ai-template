<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * @property-read string|null $calculated_hash
 * @property array<string, mixed>|null $changes
 */
class AuditEntry extends Activity
{
    protected $table = 'audit_entries';

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->action = $entry->event ?? $entry->description;
            $causerId = $entry->causer_id;
            $subjectId = $entry->subject_id;
            $entry->setAttribute('user_id', $entry->causer_type === User::class && is_numeric($causerId) && (int) $causerId > 0 ? (int) $causerId : null);
            $entry->setAttribute('document_id', $entry->subject_type === Document::class && is_numeric($subjectId) && (int) $subjectId > 0 ? (int) $subjectId : null);
            $entry->setAttribute('changes', $entry->properties?->all() ?? []);
        });
        static::updating(fn () => throw new \LogicException('Audit-Einträge sind unveränderbar.'));
        static::deleting(fn () => throw new \LogicException('Audit-Einträge sind unveränderbar.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'changes' => 'array', 'chain_position' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
