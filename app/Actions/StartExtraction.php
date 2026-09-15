<?php

namespace App\Actions;

use App\Enums\RunStatus;
use App\Jobs\ExtractDocument;
use App\Models\AiRun;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final class StartExtraction
{
    public function handle(User $actor, Document $document, bool $retry = true): AiRun
    {
        return DB::transaction(function () use ($actor, $document, $retry): AiRun {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize($retry ? 'retry' : 'update', $document);
            abort_if($document->runs()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists(), 409, 'Verarbeitung läuft bereits.');
            abort_if(! $retry && $document->runs()->exists(), 409);
            $driver = config()->string('ai.driver');
            $run = $document->runs()->create([
                'input_version' => $document->input_version, 'document_revision' => $document->revision,
                'provider' => $driver, 'model' => $driver === 'fake' ? 'deterministic-v1' : config()->string('ai.model'),
                'prompt_version' => config()->string('ai.prompt_version'), 'fake_scenario' => config()->string('ai.fake_scenario'),
                'available_at' => now(), 'status' => RunStatus::Queued,
            ]);
            DB::afterCommit(fn () => $this->dispatch($run));
            Audit::record('extraction_requested', $actor, $document, ['run_id' => $run->id]);

            return $run;
        });
    }

    public function dispatch(AiRun $run): void
    {
        try {
            ExtractDocument::dispatch($run->id)->afterCommit();
            $run->update(['dispatched_at' => now()]);
        } catch (\Throwable) {
            // The committed run is a durable dispatch intent; recovery re-enqueues it.
            Log::error('AI dispatch unavailable', ['run_id' => $run->id]);
        }
    }
}
