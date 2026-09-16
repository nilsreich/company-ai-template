<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\ApproveDocument;
use App\Actions\RestoreDocumentRevision;
use App\Actions\StartExtraction;
use App\Actions\SubmitGoldenDataset;
use App\Enums\RunStatus;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
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

    /** @return Collection<int, AuditEntry> */
    public function auditHistory(): Collection
    {
        Gate::authorize('view', $this->document());

        return AuditEntry::where('document_id', $this->document()->id)->orderByDesc('chain_position')->limit(100)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Werte korrigieren')->icon(Heroicon::PencilSquare),
            Action::make('restoreRevision')->label('Früheren Stand übernehmen')->icon(Heroicon::ArrowUturnLeft)->modalHeading('Früheren Stand als neue Revision übernehmen')->authorize('update')
                ->schema([Select::make('entry_id')->label('Historischer Stand')->required()->options(fn (): array => AuditEntry::where('document_id', $this->document()->id)->whereIn('action', ['corrected', 'revision_restored', 'field_reset', 'extraction_completed'])->orderByDesc('chain_position')->limit(100)->get()->filter(function (AuditEntry $entry): bool {
                    $changes = $entry->changes;

                    return is_array($changes) && ! empty($changes['after']['supplier']);
                })->mapWithKeys(function (AuditEntry $entry): array {
                    $changes = is_array($entry->changes) ? $entry->changes : [];

                    return [$entry->id => '#'.$entry->id.' · '.$entry->action.' · Revision '.($changes['revision_after'] ?? '?').' · '.($entry->created_at?->format('d.m.Y. H:i:s') ?? '?')];
                })->all())])
                ->modalDescription('Der gewählte Stand wird als neue Revision gespeichert. Die Historie bleibt erhalten.')
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    assert($actor instanceof User);
                    app(RestoreDocumentRevision::class)->handle($actor, $this->document(), $this->loadedRevision, (int) $data['entry_id']);
                    $this->refreshProcessing();
                    Notification::make()->title('Stand übernommen')->body('Der gewählte Stand wurde als neue Revision gespeichert.')->success()->send();
                }),
            Action::make('submitGolden')->label('Golden-Datensatz speichern')->icon(Heroicon::Beaker)->authorize('submitGolden')->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                $fixture = app(SubmitGoldenDataset::class)->handle($actor, $this->document());
                Notification::make()->title('Golden Dataset gespeichert')->body($fixture.' · nur intern, nicht in Git')->success()->send();
            }),
            Action::make('download')->label('Original herunterladen')->icon(Heroicon::ArrowDownTray)->authorize('download')->url(fn () => route('documents.download', $this->document()))->openUrlInNewTab(),
            Action::make('approve')->label('Freigeben')->icon(Heroicon::Check)->color('success')->authorize('approve')->requiresConfirmation()->modalHeading('Dokument freigeben')->modalDescription('Freigegebene Dokumente sind anschließend schreibgeschützt.')->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                app(ApproveDocument::class)->handle($actor, $this->document(), $this->loadedRevision);
                $this->refreshProcessing();
                Notification::make()->title('Dokument freigegeben')->success()->send();
            }),
            Action::make('export')->label('CSV exportieren')->icon(Heroicon::DocumentArrowDown)->authorize('export')->url(fn () => route('documents.export', $this->document()))->openUrlInNewTab(),
            Action::make('retry')->label('Erneut verarbeiten')->icon(Heroicon::ArrowPath)->color('warning')->authorize('retry')->requiresConfirmation()->modalHeading('Erneut verarbeiten')->modalDescription('Die KI-Extraktion wird erneut gestartet.')->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                app(StartExtraction::class)->handle($actor, $this->document());
                $this->refreshProcessing();
                Notification::make()->title('Verarbeitung gestartet')->body('Die erneute KI-Verarbeitung läuft im Hintergrund.')->success()->send();
            }),
        ];
    }
}
