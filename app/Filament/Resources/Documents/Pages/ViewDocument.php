<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\ApproveDocument;
use App\Actions\StartExtraction;
use App\Enums\RunStatus;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected string $view = 'filament.documents.view';

    #[Locked]
    public int $loadedRevision = 0;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadedRevision = $this->document()->revision;
    }

    public function document(): Document
    {
        $record = $this->getRecord();
        assert($record instanceof Document);

        return $record;
    }

    public function pending(): bool
    {
        return $this->document()->runs()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists();
    }

    public function refreshProcessing(): void
    {
        Gate::authorize('view', $this->document());
        $this->document()->refresh();
        $this->loadedRevision = $this->document()->revision;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Werte korrigieren'),
            Action::make('download')->label('Original herunterladen')->authorize('download')->url(fn () => route('documents.download', $this->document())),
            Action::make('approve')->label('Freigeben')->authorize('approve')->requiresConfirmation()->modalDescription('Freigegebene Dokumente sind anschließend schreibgeschützt.')->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                app(ApproveDocument::class)->handle($actor, $this->document(), $this->loadedRevision);
                $this->refreshProcessing();
            }),
            Action::make('export')->label('CSV exportieren')->authorize('export')->url(fn () => route('documents.export', $this->document())),
            Action::make('retry')->label('Erneut verarbeiten')->authorize('retry')->requiresConfirmation()->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                app(StartExtraction::class)->handle($actor, $this->document());
                $this->refreshProcessing();
            }),
        ];
    }
}
