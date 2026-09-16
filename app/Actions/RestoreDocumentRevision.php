<?php

namespace App\Actions;

use App\Ai\ValidateExtraction;
use App\Enums\DocumentStatus;
use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RestoreDocumentRevision
{
    public function handle(User $actor, Document $document, int $revision, int $entryId): Document
    {
        return DB::transaction(function () use ($actor, $document, $revision, $entryId): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize('update', $document);
            if ($document->revision !== $revision) {
                throw ValidationException::withMessages(['document' => 'Das Dokument wurde inzwischen geändert. Bitte neu laden.']);
            }
            $entry = AuditEntry::where('document_id', $document->id)->whereIn('action', ['corrected', 'revision_restored', 'field_reset', 'extraction_completed'])->findOrFail($entryId);
            $changes = $entry->changes;
            abort_unless(is_array($changes) && isset($changes['after']) && is_array($changes['after']), 422, 'Historischer Stand ist unvollständig.');
            $values = app(ValidateExtraction::class)->handle($changes['after']);
            $before = $document->extractionFields();
            $document->update([...$values, 'revision' => $revision + 1, 'status' => DocumentStatus::InReview]);
            Audit::record('revision_restored', $actor, $document, ['source_entry_id' => $entry->id, 'before' => $before, 'after' => $document->extractionFields(), 'revision_before' => $revision, 'revision_after' => $revision + 1]);

            return $document;
        });
    }
}
