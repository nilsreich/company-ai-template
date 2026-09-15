<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('original_text')->label('Originaltext')->state(fn (?Document $record) => $record ? Storage::disk('private')->get($record->path) : '')->hiddenOn('create')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap; max-height: 20rem; overflow-y: auto']),
            FileUpload::make('file')->label('UTF-8-TXT-Datei')->disk('private')->visibility('private')->storeFiles(false)->rules(['extensions:txt'])->maxSize(config()->integer('documents.max_kib'))->required()->visibleOn('create')->columnSpanFull(),
            TextInput::make('supplier')->label('Lieferant')->required()->maxLength(255)->hiddenOn('create'),
            TextInput::make('invoice_number')->label('Rechnungsnummer')->required()->maxLength(120)->hiddenOn('create'),
            DatePicker::make('invoice_date')->label('Rechnungsdatum')->required()->native()->hiddenOn('create'),
            TextInput::make('total_amount')->label('Gesamtbetrag')->required()->helperText('Dezimalpunkt, keine Tausendertrennzeichen.')->hiddenOn('create'),
            TextInput::make('currency')->label('Währung')->required()->length(3)->hiddenOn('create'),
        ]);
    }
}
