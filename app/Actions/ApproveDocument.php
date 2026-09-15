<?php

namespace App\Actions;

use App\Ai\ValidateExtraction;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApproveDocument
{
    public function __construct(private ValidateExtraction $validate) {}

    public function handle(User $actor, Document $document, int $revision): void
    {
        DB::transaction(function () use ($actor, $document, $revision): void {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize('approve', $document);
            if ($document->revision !== $revision) {
                throw ValidationException::withMessages(['document' => 'Geändertes Dokument bitte erneut prüfen.']);
            }
            $this->validate->handle($document->extractionFields());
            $document->update(['status' => DocumentStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(), 'revision' => $revision + 1]);
            Audit::record('approved', $actor, $document, ['revision' => $revision]);
        });
    }
}
