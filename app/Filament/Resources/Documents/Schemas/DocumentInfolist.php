<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Ai\FieldAssessment;
use App\Enums\DocumentStatus;
use App\Models\Document;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $fields = [];
        foreach (DocumentForm::LABELS as $name => $label) {
            $fields[] = TextEntry::make($name)->label($label)->placeholder('Nicht angegeben')->badge()
                ->color(fn (Document $record): string => self::assessment($record, $name)['color'])
                ->helperText(function (Document $record) use ($name): string {
                    $assessment = self::assessment($record, $name);

                    return $assessment['label'].': '.$assessment['reason'].($assessment['confidence'] !== null ? ' KI: '.round($assessment['confidence'] * 100).' %.' : ' KI-Konfidenz unbekannt.');
                });
        }

        return $schema->components([
            TextEntry::make('original_name')->label('Datei'),
            TextEntry::make('status')->label('Geschäftsstatus')->badge()->color(fn ($state): string => match ($state) {
                DocumentStatus::Draft => 'gray',
                DocumentStatus::InReview => 'warning',
                DocumentStatus::Approved => 'success',
                default => 'gray',
            }),
            TextEntry::make('revision')->label('Revision'),
            TextEntry::make('run_status')->label('KI-Verarbeitung')->placeholder('Noch nicht gestartet')->state(fn (Document $record) => $record->runs()->latest('id')->first()?->status->value)->badge()->color(fn (?string $state): string => match ($state) {
                'queued', 'running' => 'warning',
                'succeeded' => 'success',
                'failed' => 'danger',
                default => 'gray',
            }),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Originalbeleg')->schema([View::make('filament.documents.original')->viewData(fn (Document $record): array => ['document' => $record])]),
                Section::make('Prüfergebnis')->schema($fields),
            ])->columnSpanFull(),
            TextEntry::make('ai_result')->label('Ursprüngliches KI-Ergebnis (erste übernommene Extraktion)')->placeholder('Noch kein KI-Ergebnis vorhanden.')->state(function (Document $record): ?string {
                $run = $record->originalAiRun();
                $json = $run !== null ? json_encode($run->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : false;

                return is_string($json) ? $json : null;
            })->copyable()->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']),
        ]);
    }

    /** @return array{color: string, label: string, reason: string, confidence: int|float|null} */
    private static function assessment(Document $record, string $name): array
    {
        $run = $record->originalAiRun();
        $confidence = $run !== null && is_array($run->confidence) ? $run->confidence : [];
        $result = $run !== null && is_array($run->result) ? $run->result : [];

        return app(FieldAssessment::class)->assess($record->extractionFields(), $confidence, $result)[$name];
    }
}
