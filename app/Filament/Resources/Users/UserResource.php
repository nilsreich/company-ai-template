<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->disabled()->dehydrated(false), TextInput::make('email')->disabled()->dehydrated(false), Select::make('role')->label('Rolle')->options(Role::options())->required(), Toggle::make('active')->label('Aktiv')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Name')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('role')->label('Rolle')->badge()->color(fn ($state): string => match ($state) {
            Role::Editor => 'gray',
            Role::Reviewer => 'warning',
            Role::Admin => 'success',
            default => 'gray',
        }), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([TernaryFilter::make('active')->label('Aktiv')])->recordActions([EditAction::make()->label('Bearbeiten')])->defaultSort('id', 'desc')
            ->emptyStateHeading('Keine Benutzer vorhanden');
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'edit' => EditUser::route('/{record}/edit')];
    }
}
