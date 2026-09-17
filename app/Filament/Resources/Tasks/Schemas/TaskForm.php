<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\Task;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('file')->label('PDF oder UTF-8-TXT-Datei')->helperText('TXT bis 256 KiB, PDF bis 8 MiB. Die Datei bleibt privat gespeichert.')->disk('private')->visibility('private')->storeFiles(false)->acceptedFileTypes(['application/pdf', 'text/plain'])->rules(['extensions:pdf,txt'])->maxSize(config()->integer('tasks.pdf_max_kib'))->required()->visibleOn('create')->columnSpanFull(),
            TextInput::make('title')->label('Titel')->maxLength(255)->required()->hiddenOn('create')->columnSpanFull(),
            Textarea::make('payload_json')->label('Ergebnis (JSON)')->rows(12)->required()->hiddenOn('create')->columnSpanFull()
                ->hint(fn (Get $get): string => self::payloadHint($get('payload_json')))
                ->hintColor(fn (Get $get): string => self::payloadValid($get('payload_json')) ? 'success' : 'danger')
                ->helperText('Der KI-Vorschlag als JSON-Objekt. Fachmodule validieren die Struktur serverseitig erneut.'),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Original')->schema([View::make('filament.tasks.original')->viewData(fn (?Task $record): array => ['task' => $record])]),
                Section::make('Hinweis')->schema([])->description('Fachspezifische Felder, Prüfhinweise und Formel-Export liefert das Domain-Modul (siehe examples/).'),
            ])->hiddenOn('create')->columnSpanFull(),
        ]);
    }

    public static function payloadValid(mixed $json): bool
    {
        if (! is_string($json)) {
            return false;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($data) && $data !== [];
    }

    private static function payloadHint(mixed $json): string
    {
        return self::payloadValid($json) ? 'Gültiges JSON-Objekt' : 'Kein gültiges, nicht-leeres JSON-Objekt';
    }

    /** @return array<string, mixed> */
    public static function decodePayload(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['payload_json' => 'Kein gültiges JSON-Objekt.']);
        }
        if (! is_array($data)) {
            throw ValidationException::withMessages(['payload_json' => 'Kein JSON-Objekt.']);
        }

        return $data;
    }
}
