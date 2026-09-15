<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\UpdateUserAccess;
use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof User);
        $actor = auth()->user();
        assert($actor instanceof User);

        return app(UpdateUserAccess::class)->handle($actor, $record, Role::from($data['role']), (bool) $data['active']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
