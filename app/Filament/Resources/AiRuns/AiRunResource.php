<?php

namespace App\Filament\Resources\AiRuns;

use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Models\AiRun;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiRunResource extends Resource
{
    protected static ?string $model = AiRun::class;

    protected static ?string $modelLabel = 'KI-Lauf';

    protected static ?string $pluralModelLabel = 'KI-Läufe';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Lauf'), TextColumn::make('document.original_name')->label('Dokument')->searchable(),
            TextColumn::make('status')->badge(), TextColumn::make('attempts')->label('Versuche'),
            TextColumn::make('error_category')->label('Fehlerkategorie'), TextColumn::make('created_at')->dateTime(),
        ])->filters([SelectFilter::make('status')->options(['queued' => 'Wartend', 'running' => 'Läuft', 'succeeded' => 'Erfolgreich', 'failed' => 'Fehlgeschlagen'])])->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $entries = [];
        foreach (['document.original_name', 'status', 'input_version', 'document_revision', 'provider', 'model', 'prompt_version', 'attempts', 'started_at', 'finished_at', 'error_category', 'applied'] as $field) {
            $entries[] = TextEntry::make($field);
        }
        foreach (['result', 'usage'] as $field) {
            $entries[] = TextEntry::make($field)->state(fn (AiRun $record) => json_encode($record->$field, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->columnSpanFull();
        }

        return $schema->components($entries);
    }

    public static function getPages(): array
    {
        return ['index' => ListAiRuns::route('/'), 'view' => ViewAiRun::route('/{record}')];
    }
}
