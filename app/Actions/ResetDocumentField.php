<?php

namespace App\Actions;

use App\Ai\ValidateExtraction;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ResetDocumentField
{
    public function handle(User $actor, Document $document, int $revision, string $field): Document
    {
        return DB::transaction(function () use ($actor, $document, $revision, $field): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize('update', $document);
            abort_unless(in_array($field, Document::FIELDS, true), 422);
            if ($document->revision !== $revision) {
                throw ValidationException::withMessages([$field => 'Das Dokument wurde inzwischen geändert. Bitte neu laden.']);
            }
            $run = $document->originalAiRun();
            abort_unless($run && is_array($run->result) && array_key_exists($field, $run->result), 422, 'Kein ursprünglicher KI-Wert vorhanden.');
            $before = $document->extractionFields();
            $values = app(ValidateExtraction::class)->handle([...$before, $field => $run->result[$field]]);
            $document->update([...$values, 'revision' => $revision + 1, 'status' => DocumentStatus::InReview]);
            Audit::record('field_reset', $actor, $document, ['field' => $field, 'source_run_id' => $run->id, 'before' => $before, 'after' => $document->extractionFields(), 'revision_before' => $revision, 'revision_after' => $revision + 1]);

            return $document;
        });
    }
}
