<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label('Titel'),
            TextEntry::make('original_name')->label('Datei'),
            TextEntry::make('status')->label('Status')->badge()->color(fn ($state): string => match ($state) {
                TaskStatus::Draft => 'gray',
                TaskStatus::InReview => 'warning',
                TaskStatus::Approved => 'success',
                default => 'gray',
            }),
            TextEntry::make('revision')->label('Revision'),
            TextEntry::make('execution_status')->label('KI-Verarbeitung')->placeholder('Noch nicht gestartet')->state(fn (Task $record) => $record->executions()->latest('id')->first()?->status->value)->badge()->color(fn (?string $state): string => match ($state) {
                'queued', 'running' => 'warning',
                'succeeded' => 'success',
                'failed' => 'danger',
                default => 'gray',
            }),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Original')->schema([View::make('filament.tasks.original')->viewData(fn (Task $record): array => ['task' => $record])]),
                Section::make('Aktuelles Ergebnis')->schema([
                    TextEntry::make('payload_pretty')->label('Ergebnis')->placeholder('Noch kein Ergebnis vorhanden.')->state(function (Task $record): ?string {
                        $payload = $record->payload();
                        if ($payload === []) {
                            return null;
                        }
                        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                        return is_string($json) ? $json : null;
                    })->copyable()->extraAttributes(['style' => 'white-space: pre-wrap']),
                ]),
            ])->columnSpanFull(),
            TextEntry::make('ai_result')->label('Ursprüngliches KI-Ergebnis (erste übernommene Ausführung)')->placeholder('Noch kein KI-Ergebnis vorhanden.')->state(function (Task $record): ?string {
                $execution = $record->originalExecution();
                $json = $execution !== null ? json_encode($execution->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : false;

                return is_string($json) ? $json : null;
            })->copyable()->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']),
        ]);
    }
}
