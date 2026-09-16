<?php

namespace App\Actions;

use App\Ai\ValidateExtraction;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CorrectDocument
{
    public function __construct(private ValidateExtraction $validate) {}

    /** @param array<string, mixed> $fields */
    public function handle(User $actor, Document $document, int $revision, array $fields): Document
    {
        return DB::transaction(function () use ($actor, $document, $revision, $fields): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize('update', $document);
            if ($document->revision !== $revision) {
                throw ValidationException::withMessages(['supplier' => 'Das Dokument wurde inzwischen geändert. Bitte neu laden.']);
            }
            $values = $this->validate->handle($fields);
            $before = $document->extractionFields();
            $document->update([...$values, 'revision' => $revision + 1, 'status' => DocumentStatus::InReview]);
            Audit::record('corrected', $actor, $document, ['before' => $before, 'after' => $document->extractionFields(), 'revision_before' => $revision, 'revision_after' => $revision + 1]);

            return $document;
        });
    }
}
