<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class UploadDocument
{
    public function __construct(private StartExtraction $start) {}

    public function handle(User $actor, UploadedFile $file): Document
    {
        Gate::forUser($actor)->authorize('create', Document::class);
        $text = $file->getContent();
        $extension = strtolower($file->getClientOriginalExtension());
        $pdf = $extension === 'pdf';
        $validPdf = str_starts_with($text, '%PDF-') && str_contains(substr($text, -2048), '%%EOF') && $file->getMimeType() === 'application/pdf';
        $validText = $extension === 'txt' && trim($text) !== '' && mb_check_encoding($text, 'UTF-8') && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
        $limit = config()->integer($pdf ? 'documents.pdf_max_kib' : 'documents.max_kib') * 1024;
        if (! $file->isValid() || strlen($text) > $limit || ! ($pdf ? $validPdf : $validText)) {
            throw ValidationException::withMessages(['file' => 'Bitte eine gültige PDF- oder UTF-8-TXT-Datei innerhalb der Größenbegrenzung hochladen.']);
        }
        $path = $file->store('documents', 'private');
        if (! is_string($path)) {
            throw new \RuntimeException('Private Datei konnte nicht gespeichert werden.');
        }
        try {
            return DB::transaction(function () use ($actor, $file, $path, $text, $pdf): Document {
                $document = Document::create(['uploaded_by' => $actor->id, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'path' => $path, 'mime_type' => $pdf ? 'application/pdf' : 'text/plain', 'sha256' => hash('sha256', $text)]);
                $document->refresh();
                Audit::record('document_received', $actor, $document, ['sha256' => $document->sha256, 'mime_type' => $document->mime_type, 'size' => strlen($text), 'revision' => 0]);
                $this->start->handle($actor, $document, false);

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }
    }
}
