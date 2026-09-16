<?php

namespace App\Filament\Resources\Documents\Tables;

use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('original_name')->label('Datei')->searchable()->placeholder('–'),
            TextColumn::make('supplier')->label('Lieferant')->searchable()->placeholder('–'),
            TextColumn::make('invoice_number')->label('Rechnungsnummer')->searchable()->placeholder('–'),
            TextColumn::make('status')->label('Status')->badge()->color(fn ($state): string => match ($state->value ?? $state) {
                'draft' => 'gray',
                'in_review' => 'warning',
                'approved' => 'success',
                default => 'gray',
            }),
            TextColumn::make('created_at')->label('Hochgeladen')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->label('Status')->options(['draft' => 'Entwurf', 'in_review' => 'In Prüfung', 'approved' => 'Freigegeben'])])->recordActions([ViewAction::make()->icon(Heroicon::Eye)->label('Ansehen')])->defaultSort('id', 'desc')
            ->emptyStateHeading('Keine Dokumente vorhanden')
            ->emptyStateDescription('Laden Sie oben rechts über „Erstellen“ das erste Dokument hoch.')
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack);
    }
}
