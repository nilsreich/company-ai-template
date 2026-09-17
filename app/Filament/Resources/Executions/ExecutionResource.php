<?php

namespace App\Filament\Resources\Executions;

use App\Enums\ExecutionStatus;
use App\Filament\Resources\Executions\Pages\ListExecutions;
use App\Filament\Resources\Executions\Pages\ViewExecution;
use App\Models\Execution;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExecutionResource extends Resource
{
    protected static ?string $model = Execution::class;

    protected static ?string $modelLabel = 'KI-Lauf';

    protected static ?string $pluralModelLabel = 'KI-Läufe';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CpuChip;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Lauf')->sortable(), TextColumn::make('task.title')->label('Aufgabe')->searchable()->placeholder('–'),
            TextColumn::make('status')->label('Status')->badge()->color(fn ($state): string => match ($state) {
                ExecutionStatus::Queued, ExecutionStatus::Running => 'warning',
                ExecutionStatus::Succeeded => 'success',
                ExecutionStatus::Failed => 'danger',
                default => 'gray',
            }), TextColumn::make('attempts')->label('Versuche')->sortable(),
            TextColumn::make('error_category')->label('Fehlerkategorie')->placeholder('–'), TextColumn::make('created_at')->label('Gestartet')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->label('Status')->options(['queued' => 'Wartend', 'running' => 'Läuft', 'succeeded' => 'Erfolgreich', 'failed' => 'Fehlgeschlagen'])])->recordActions([ViewAction::make()->label('Ansehen')])->defaultSort('id', 'desc')
            ->emptyStateHeading('Keine KI-Läufe vorhanden')
            ->emptyStateDescription('KI-Läufe entstehen automatisch nach einem Aufgaben-Upload.');
    }

    public static function infolist(Schema $schema): Schema
    {
        $entries = [];
        foreach (['task.title' => 'Aufgabe', 'status' => 'Status', 'input_version' => 'Eingaberevision', 'task_revision' => 'Aufgabenrevision', 'provider' => 'Anbieter', 'model' => 'Modell', 'prompt_version' => 'Prompt-Version', 'attempts' => 'Versuche', 'started_at' => 'Gestartet', 'finished_at' => 'Beendet', 'error_category' => 'Fehlerkategorie', 'applied' => 'Übernommen'] as $field => $label) {
            $entries[] = TextEntry::make($field)->label($label)->placeholder('–');
        }
        foreach (['result' => 'Validiertes Ergebnis', 'usage' => 'Token-Verbrauch'] as $field => $label) {
            $entries[] = TextEntry::make($field)->label($label)->placeholder('–')->state(fn (Execution $record) => $record->$field !== null ? json_encode($record->$field, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null)->copyable()->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-wrap']);
        }

        return $schema->components($entries);
    }

    public static function getPages(): array
    {
        return ['index' => ListExecutions::route('/'), 'view' => ViewExecution::route('/{record}')];
    }
}
