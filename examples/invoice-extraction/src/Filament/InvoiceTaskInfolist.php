<?php

namespace Examples\InvoiceExtraction\Filament;

use App\Models\Task;
use Examples\InvoiceExtraction\Support\InvoiceFieldAssessment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** Feldgenaue Rechnungsansicht als Ergänzung zur generischen TaskInfolist. */
class InvoiceTaskInfolist
{
    /** @return array<int, TextEntry> */
    public static function entries(): array
    {
        $fields = [];
        foreach (InvoiceTaskForm::LABELS as $name => $label) {
            $fields[] = TextEntry::make('payload.'.$name)->label($label)->placeholder('Nicht angegeben')->badge()
                ->color(fn (Task $record): string => self::assessment($record, $name)['color'])
                ->helperText(function (Task $record) use ($name): string {
                    $assessment = self::assessment($record, $name);

                    return $assessment['label'].': '.$assessment['reason'].($assessment['confidence'] !== null ? ' KI: '.round($assessment['confidence'] * 100).' %.' : ' KI-Konfidenz unbekannt.');
                });
        }

        return $fields;
    }

    /** @return array{color: string, label: string, reason: string, confidence: int|float|null} */
    private static function assessment(Task $record, string $name): array
    {
        $execution = $record->originalExecution();
        $confidence = $execution !== null && is_array($execution->confidence) ? $execution->confidence : [];
        $result = $execution !== null && is_array($execution->result) ? $execution->result : [];

        return app(InvoiceFieldAssessment::class)->assess($record->payload(), $confidence, $result)[$name];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::entries());
    }
}
