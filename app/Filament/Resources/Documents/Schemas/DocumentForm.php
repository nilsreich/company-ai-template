<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Ai\FieldAssessment;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DocumentForm
{
    public const LABELS = ['supplier' => 'Lieferant', 'invoice_number' => 'Rechnungsnummer', 'invoice_date' => 'Rechnungsdatum', 'total_amount' => 'Gesamtbetrag', 'currency' => 'Währung', 'net_amount' => 'Netto', 'tax_amount' => 'Umsatzsteuer', 'iban' => 'IBAN'];

    public static function configure(Schema $schema): Schema
    {
        $fields = [];
        foreach (self::LABELS as $name => $label) {
            $component = $name === 'invoice_date'
                ? DatePicker::make($name)->displayFormat('d.m.Y')->weekStartsOnMonday()->closeOnDateSelection()
                : TextInput::make($name)
                    ->maxLength(match ($name) {
                        'supplier' => 255, 'invoice_number' => 120, 'currency' => 3, 'iban' => 34, default => 32
                    })
                    ->live(onBlur: true);
            $fields[] = $component->label($label)->required(in_array($name, array_slice(Document::FIELDS, 0, 5), true))
                ->hint(fn (Get $get, ?Document $record): string => self::assessment($name, $get, $record)['label'])
                ->hintColor(fn (Get $get, ?Document $record): string => self::assessment($name, $get, $record)['color'])
                ->helperText(function (Get $get, ?Document $record) use ($name): string {
                    $assessment = self::assessment($name, $get, $record);

                    return $assessment['reason'].($assessment['confidence'] !== null ? ' KI-Selbsteinschätzung: '.round($assessment['confidence'] * 100).' %.' : ' Keine belastbare KI-Konfidenz vorhanden.');
                })
                ->suffixAction(Action::make('reset_'.$name)->icon(Heroicon::ArrowUturnLeft)->label('Ursprünglichen KI-Wert wiederherstellen')->tooltip('KI-Wert wiederherstellen und als neue Revision speichern')
                    ->visible(function (?Document $record) use ($name): bool {
                        if ($record === null) {
                            return false;
                        }
                        $run = $record->originalAiRun();
                        $result = $run !== null && is_array($run->result) ? $run->result : [];

                        return array_key_exists($name, $result) && (bool) auth()->user()?->can('update', $record);
                    })
                    ->action(fn (EditDocument $livewire) => $livewire->resetAiField($name)));
        }

        return $schema->components([
            FileUpload::make('file')->label('PDF oder UTF-8-TXT-Datei')->helperText('TXT bis 256 KiB, PDF bis 8 MiB. Die Datei bleibt privat gespeichert.')->disk('private')->visibility('private')->storeFiles(false)->acceptedFileTypes(['application/pdf', 'text/plain'])->rules(['extensions:pdf,txt'])->maxSize(config()->integer('documents.pdf_max_kib'))->required()->visibleOn('create')->columnSpanFull(),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Originalbeleg')->schema([View::make('filament.documents.original')->viewData(fn (?Document $record): array => ['document' => $record])]),
                Section::make('Extrahierte Werte prüfen')->description('Grün prüft Rechenregeln, nicht die Übereinstimmung mit dem Original.')->schema($fields),
            ])->hiddenOn('create')->columnSpanFull(),
        ]);
    }

    /** @return array{color: string, label: string, reason: string, confidence: int|float|null} */
    private static function assessment(string $field, Get $get, ?Document $record): array
    {
        $values = [];
        foreach (Document::FIELDS as $name) {
            $values[$name] = $get($name);
        }
        $run = $record !== null ? $record->originalAiRun() : null;
        $confidence = $run !== null && is_array($run->confidence) ? $run->confidence : [];
        $result = $run !== null && is_array($run->result) ? $run->result : [];

        return app(FieldAssessment::class)->assess($values, $confidence, $result)[$field];
    }
}
