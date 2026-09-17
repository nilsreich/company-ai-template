<?php

namespace Examples\InvoiceExtraction\Filament\Pages;

use App\Actions\CorrectTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Models\Task;
use App\Models\User;
use Examples\InvoiceExtraction\Filament\InvoiceTaskForm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Edit-Seite, die die 8 Rechnungsfelder auf den Task-Payload abbildet. */
class EditInvoiceTask extends EditTask
{
    #[Locked]
    public int $loadedRevision = 0;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Task $record */
        $record = $this->getRecord();
        $this->loadedRevision = $record->revision;
        $data['title'] = $record->title;
        foreach (array_keys(InvoiceTaskForm::LABELS) as $name) {
            $data[$name] = $record->payload()[$name] ?? null;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Task);
        $actor = auth()->user();
        assert($actor instanceof User);
        try {
            $payload = [];
            foreach (array_keys(InvoiceTaskForm::LABELS) as $name) {
                $payload[$name] = $data[$name] ?? null;
            }
            $updated = app(CorrectTask::class)->handle($actor, $record, $this->loadedRevision, $payload);
            $updated->update(['title' => mb_substr((string) ($data['title'] ?? $updated->title), 0, 255)]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
        $this->loadedRevision = $updated->revision;

        return $updated;
    }
}
