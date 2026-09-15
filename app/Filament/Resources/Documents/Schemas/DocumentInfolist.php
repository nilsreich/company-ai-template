<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('original_name')->label('Datei'), TextEntry::make('status')->label('Geschäftsstatus')->badge(),
            TextEntry::make('supplier')->label('Lieferant'), TextEntry::make('invoice_number')->label('Rechnungsnummer'),
            TextEntry::make('invoice_date')->label('Rechnungsdatum'), TextEntry::make('total_amount')->label('Gesamtbetrag'), TextEntry::make('currency')->label('Währung'),
            TextEntry::make('run_status')->label('KI-Verarbeitung')->state(fn (Document $record) => $record->runs()->latest('id')->first()?->status->value)->badge(),
            TextEntry::make('original_text')->label('Originaltext')->state(fn (Document $record) => Storage::disk('private')->get($record->path))->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere; max-height: 28rem; overflow-y: auto']),
            TextEntry::make('ai_result')->label('Ursprüngliches KI-Ergebnis')->state(fn (Document $record) => json_encode($record->runs()->whereNotNull('result')->latest('id')->first()?->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']),
        ]);
    }
}
