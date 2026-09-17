<?php

namespace Examples\InvoiceExtraction\Export;

use App\Actions\Audit;
use App\Models\Task;
use App\Models\User;
use Examples\InvoiceExtraction\Agents\InvoiceExtraction;
use Illuminate\Support\Facades\Gate;

/** CSV-Export des freigegebenen Rechnungs-Payloads (Spalten in FIELDS-Reihenfolge). */
final class InvoiceCsvExporter
{
    public function handle(User $actor, Task $task): string
    {
        $task->refresh();
        Gate::forUser($actor)->authorize('export', $task);
        $payload = $task->payload();
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('CSV konnte nicht erstellt werden.');
        }
        fputcsv($stream, ['Lieferant', 'Rechnungsnummer', 'Rechnungsdatum', 'Gesamtbetrag', 'Währung', 'Netto', 'Umsatzsteuer', 'IBAN'], ';', '"', '', "\r\n");
        $row = [];
        foreach (InvoiceExtraction::FIELDS as $field) {
            $row[] = $this->safeCell($payload[$field] ?? null);
        }
        fputcsv($stream, $row, ';', '"', '', "\r\n");
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \RuntimeException('CSV konnte nicht gelesen werden.');
        }
        Audit::record('exported', $actor, $task, ['revision' => $task->revision, 'sha256' => hash('sha256', "\xEF\xBB\xBF".$csv), 'payload' => $payload]);

        return "\xEF\xBB\xBF".$csv;
    }

    private function safeCell(mixed $value): string
    {
        $cell = is_string($value) ? $value : '';

        return preg_match('/^[\s\x00-\x20]*[=+\-@]|^[\t\r\n]/u', $cell) ? "'".$cell : $cell;
    }
}
