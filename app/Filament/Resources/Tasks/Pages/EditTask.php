<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Actions\CorrectTask;
use App\Actions\ResetTaskField;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    #[Locked]
    public int $loadedRevision = 0;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedRevision = (int) $data['revision'];
        /** @var Task $record */
        $record = $this->getRecord();
        $data['payload_json'] = json_encode($record->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Task);
        $actor = auth()->user();
        assert($actor instanceof User);
        try {
            $payload = TaskForm::decodePayload((string) ($data['payload_json'] ?? ''));
            $updated = app(CorrectTask::class)->handle($actor, $record, $this->loadedRevision, $payload);
            $updated->update(['title' => mb_substr((string) ($data['title'] ?? $updated->title), 0, 255)]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        } catch (\JsonException) {
            throw ValidationException::withMessages(['data.payload_json' => 'Kein gültiges JSON-Objekt.']);
        }
        $this->loadedRevision = $updated->revision;

        return $updated;
    }

    public function resetTaskField(string $field): void
    {
        $record = $this->getRecord();
        assert($record instanceof Task);
        $actor = auth()->user();
        assert($actor instanceof User);
        $updated = app(ResetTaskField::class)->handle($actor, $record, $this->loadedRevision, $field);
        $this->record = $updated;
        $this->loadedRevision = $updated->revision;
        $this->data['payload_json'] = json_encode($updated->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        Notification::make()->title('KI-Wert wiederhergestellt')->body('Das Feld wurde als neue Revision gespeichert.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
