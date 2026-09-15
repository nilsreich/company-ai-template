<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ExportDocument
{
    public function handle(User $actor, Document $document): string
    {
        $document->refresh();
        Gate::forUser($actor)->authorize('export', $document);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('CSV konnte nicht erstellt werden.');
        }
        fputcsv($stream, ['Lieferant', 'Rechnungsnummer', 'Rechnungsdatum', 'Gesamtbetrag', 'Währung'], ';', '"', '', "\r\n");
        fputcsv($stream, array_map($this->safeCell(...), array_values($document->extractionFields())), ';', '"', '', "\r\n");
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \RuntimeException('CSV konnte nicht gelesen werden.');
        }
        Audit::record('exported', $actor, $document, ['revision' => $document->revision]);

        return "\xEF\xBB\xBF".$csv;
    }

    private function safeCell(mixed $value): string
    {
        $cell = is_string($value) ? $value : '';

        return preg_match('/^[\s\x00-\x20]*[=+\-@]|^[\t\r\n]/u', $cell) ? "'".$cell : $cell;
    }
}
