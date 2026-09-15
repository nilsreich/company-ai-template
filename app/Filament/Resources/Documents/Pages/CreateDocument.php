<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\UploadDocument;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);
        $file = $data['file'];
        assert($file instanceof UploadedFile);
        try {
            return app(UploadDocument::class)->handle($actor, $file);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
    }

    protected function getRedirectUrl(): string
    {
        return DocumentResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
