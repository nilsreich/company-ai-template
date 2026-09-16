<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\CorrectDocument;
use App\Actions\ResetDocumentField;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    #[Locked]
    public int $loadedRevision = 0;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedRevision = (int) $data['revision'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Document);
        $actor = auth()->user();
        assert($actor instanceof User);
        try {
            $updated = app(CorrectDocument::class)->handle($actor, $record, $this->loadedRevision, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
        $this->loadedRevision = $updated->revision;

        return $updated;
    }

    public function resetAiField(string $field): void
    {
        $record = $this->getRecord();
        assert($record instanceof Document);
        $actor = auth()->user();
        assert($actor instanceof User);
        $updated = app(ResetDocumentField::class)->handle($actor, $record, $this->loadedRevision, $field);
        $this->record = $updated;
        $this->loadedRevision = $updated->revision;
        $this->data[$field] = $updated->getAttribute($field);
        Notification::make()->title('KI-Wert wiederhergestellt')->body('Das Feld wurde als neue Revision gespeichert.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
