<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Actions\ApproveTask;
use App\Actions\RestoreTaskRevision;
use App\Actions\StartExecution;
use App\Enums\ExecutionStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\AuditEntry;
use App\Models\Task;
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

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.tasks.view';

    #[Locked]
    public int $loadedRevision = 0;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadedRevision = $this->task()->revision;
    }

    public function task(): Task
    {
        $record = $this->getRecord();
        assert($record instanceof Task);

        return $record;
    }

    public function pending(): bool
    {
        return $this->task()->executions()->whereIn('status', [ExecutionStatus::Queued, ExecutionStatus::Running])->exists();
    }

    public function refreshProcessing(): void
    {
        Gate::authorize('view', $this->task());
        $this->task()->refresh();
        $this->loadedRevision = $this->task()->revision;
    }

    /** @return Collection<int, AuditEntry> */
    public function auditHistory(): Collection
    {
        Gate::authorize('view', $this->task());

        return AuditEntry::where('task_id', $this->task()->id)->orderByDesc('chain_position')->limit(100)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Werte korrigieren')->icon(Heroicon::PencilSquare),
            Action::make('restoreRevision')->label('Früheren Stand übernehmen')->icon(Heroicon::ArrowUturnLeft)->modalHeading('Früheren Stand als neue Revision übernehmen')->authorize('update')
                ->schema([Select::make('entry_id')->label('Historischer Stand')->required()->searchable()->options(fn (): array => AuditEntry::where('task_id', $this->task()->id)->whereIn('action', ['corrected', 'revision_restored', 'field_reset', 'execution_completed'])->orderByDesc('chain_position')->limit(100)->get()->filter(function (AuditEntry $entry): bool {
                    $changes = $entry->changes;

                    return is_array($changes) && isset($changes['after']) && is_array($changes['after']) && $changes['after'] !== [];
                })->mapWithKeys(function (AuditEntry $entry): array {
                    $changes = is_array($entry->changes) ? $entry->changes : [];

                    return [$entry->id => '#'.$entry->id.' · '.$entry->action.' · Revision '.($changes['revision_after'] ?? '?').' · '.($entry->created_at?->format('d.m.Y. H:i:s') ?? '?')];
                })->all())])
                ->modalDescription('Der gewählte Stand wird als neue Revision gespeichert. Die Historie bleibt erhalten.')
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    assert($actor instanceof User);
                    app(RestoreTaskRevision::class)->handle($actor, $this->task(), $this->loadedRevision, (int) $data['entry_id']);
                    $this->refreshProcessing();
                    Notification::make()->title('Stand übernommen')->body('Der gewählte Stand wurde als neue Revision gespeichert.')->success()->send();
                }),
            Action::make('download')->label('Original herunterladen')->icon(Heroicon::ArrowDownTray)->authorize('download')->url(fn () => route('tasks.download', $this->task()))->openUrlInNewTab(),
            Action::make('approve')->label('Freigeben')->icon(Heroicon::Check)->color('success')->authorize('approve')->requiresConfirmation()->modalHeading('Aufgabe freigeben')->modalDescription('Freigegebene Aufgaben sind anschließend schreibgeschützt.')->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                app(ApproveTask::class)->handle($actor, $this->task(), $this->loadedRevision);
                $this->refreshProcessing();
                Notification::make()->title('Aufgabe freigegeben')->success()->send();
            }),
            Action::make('export')->label('JSON exportieren')->icon(Heroicon::DocumentArrowDown)->authorize('export')->url(fn () => route('tasks.export', $this->task()))->openUrlInNewTab(),
            Action::make('retry')->label('Erneut verarbeiten')->icon(Heroicon::ArrowPath)->color('warning')->authorize('retry')->requiresConfirmation()->modalHeading('Erneut verarbeiten')->modalDescription('Die KI-Verarbeitung wird erneut gestartet.')->action(function (): void {
                $actor = auth()->user();
                assert($actor instanceof User);
                app(StartExecution::class)->handle($actor, $this->task());
                $this->refreshProcessing();
                Notification::make()->title('Verarbeitung gestartet')->body('Die erneute KI-Verarbeitung läuft im Hintergrund.')->success()->send();
            }),
        ];
    }
}
