<?php

namespace Examples\InvoiceExtraction\Filament;

use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Models\Task;
use Examples\InvoiceExtraction\Support\InvoiceFieldAssessment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Feldgenaue Rechnungsmaske als Ersatz für die generische TaskForm.
 * In TaskResource::form() als InvoiceTaskForm::configure($schema) einhängen.
 */
class InvoiceTaskForm
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
            $fields[] = $component->label($label)->required(in_array($name, ['supplier', 'invoice_number', 'invoice_date', 'total_amount', 'currency'], true))
                ->hint(fn (Get $get, ?Task $record): string => self::assessment($name, $get, $record)['label'])
                ->hintColor(fn (Get $get, ?Task $record): string => self::assessment($name, $get, $record)['color'])
                ->helperText(function (Get $get, ?Task $record) use ($name): string {
                    $assessment = self::assessment($name, $get, $record);

                    return $assessment['reason'].($assessment['confidence'] !== null ? ' KI-Selbsteinschätzung: '.round($assessment['confidence'] * 100).' %.' : ' Keine belastbare KI-Konfidenz vorhanden.');
                })
                ->suffixAction(Action::make('reset_'.$name)->icon(Heroicon::ArrowUturnLeft)->label('Ursprünglichen KI-Wert wiederherstellen')->tooltip('KI-Wert wiederherstellen und als neue Revision speichern')
                    ->visible(function (?Task $record) use ($name): bool {
                        if ($record === null) {
                            return false;
                        }
                        $execution = $record->originalExecution();
                        $result = $execution !== null && is_array($execution->result) ? $execution->result : [];

                        return array_key_exists($name, $result) && (bool) auth()->user()?->can('update', $record);
                    })
                    ->action(fn (EditTask $livewire) => $livewire->resetTaskField($name)));
        }

        return $schema->components([
            FileUpload::make('file')->label('PDF oder UTF-8-TXT-Datei')->disk('private')->visibility('private')->storeFiles(false)->acceptedFileTypes(['application/pdf', 'text/plain'])->rules(['extensions:pdf,txt'])->maxSize(config()->integer('tasks.pdf_max_kib'))->required()->visibleOn('create')->columnSpanFull(),
            TextInput::make('title')->label('Titel')->maxLength(255)->required()->hiddenOn('create')->columnSpanFull(),
            ...$fields,
        ]);
    }

    /** @return array{color: string, label: string, reason: string, confidence: int|float|null} */
    private static function assessment(string $field, Get $get, ?Task $record): array
    {
        $values = [];
        foreach (array_keys(self::LABELS) as $name) {
            $values[$name] = $get($name);
        }
        $execution = $record !== null ? $record->originalExecution() : null;
        $confidence = $execution !== null && is_array($execution->confidence) ? $execution->confidence : [];
        $result = $execution !== null && is_array($execution->result) ? $execution->result : [];

        return app(InvoiceFieldAssessment::class)->assess($values, $confidence, $result)[$field];
    }
}
