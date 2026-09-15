<?php

namespace App\Filament\Resources\Documents\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('original_name')->label('Datei')->searchable(),
            TextColumn::make('supplier')->label('Lieferant')->searchable(),
            TextColumn::make('invoice_number')->label('Rechnungsnummer')->searchable(),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('created_at')->label('Hochgeladen')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->label('Status')->options(['draft' => 'Entwurf', 'in_review' => 'In Prüfung', 'approved' => 'Freigegeben'])])->recordActions([ViewAction::make()])->defaultSort('id', 'desc');
    }
}
