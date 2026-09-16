<?php

namespace App\Actions;

use App\Ai\DocumentExtractor;
use App\Ai\ExtractionFailure;
use App\Ai\ExtractionInput;
use App\Ai\FakeDocumentExtractor;
use App\Ai\OpenAiDocumentExtractor;
use App\Ai\ValidateExtraction;
use App\Enums\DocumentStatus;
use App\Enums\RunStatus;
use App\Models\AiRun;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcessExtraction
{
    public function __construct(private ValidateExtraction $validate, private DocumentExtractor $extractor) {}

    /** Returns a delay for a retry, or null for an acknowledged job. */
    public function handle(int $id): ?int
    {
        $owner = (string) Str::uuid();
        $run = DB::transaction(function () use ($id, $owner): ?AiRun {
            $run = AiRun::query()->lockForUpdate()->find($id);
            if (! $run || in_array($run->status, [RunStatus::Succeeded, RunStatus::Failed], true)) {
                return null;
            }
            if ($run->lease_until?->isFuture() || $run->available_at?->isFuture()) {
                return null;
            }
            if ($run->attempts >= config()->integer('ai.max_attempts')) {
                $run->update(['status' => RunStatus::Failed, 'error_category' => 'worker_interrupted', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);

                Audit::record('extraction_failed', null, $run->document, ['run_id' => $run->id, 'category' => 'worker_interrupted']);

                return null;
            }
            $run->update(['status' => RunStatus::Running, 'attempts' => $run->attempts + 1, 'started_at' => now(), 'lease_owner' => $owner, 'lease_until' => now()->addSeconds(config()->integer('ai.lease_seconds')), 'error_category' => null]);

            Audit::record('extraction_started', null, $run->document, ['run_id' => $run->id, 'attempt' => $run->attempts, 'model' => $run->model, 'prompt_version' => $run->prompt_version]);

            return $run;
        });
        if (! $run) {
            return null;
        }
        try {
            $document = $run->document()->firstOrFail();
            if ($document->input_version !== $run->input_version) {
                throw new ExtractionFailure('stale_input');
            }
            $text = Storage::disk('private')->get($document->path);
            if (! is_string($text)) {
                throw new ExtractionFailure('input_missing');
            }
            if (hash('sha256', $text) !== $document->sha256) {
                throw new ExtractionFailure('input_integrity');
            }
            // Bind by the persisted driver so configuration changes do not reroute old inputs.
            $extractor = $run->provider === config()->string('ai.driver') ? $this->extractor : match ($run->provider) {
                'fake' => app(FakeDocumentExtractor::class), 'live' => app(OpenAiDocumentExtractor::class), default => throw new ExtractionFailure('configuration'),
            };
            $result = $extractor->extract(new ExtractionInput($text, $run->attempts, $run->model, $run->prompt_version, $run->fake_scenario, $document->mime_type));
            $fields = $this->validate->handle($result->fields);
            DB::transaction(function () use ($run, $owner, $fields, $result): void {
                $document = Document::query()->lockForUpdate()->findOrFail($run->document_id);
                $current = AiRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($current->lease_owner !== $owner || ! $current->lease_until?->isFuture() || $current->status !== RunStatus::Running) {
                    return;
                }
                $apply = $document->input_version === $run->input_version && $document->revision === $run->document_revision && $document->status !== DocumentStatus::Approved;
                $before = $document->extractionFields();
                $revision = $document->revision;
                if ($apply) {
                    $document->update([...$fields, 'revision' => $document->revision + 1, 'status' => DocumentStatus::InReview]);
                }
                $current->update(['status' => RunStatus::Succeeded, 'result' => $fields, 'confidence' => $result->confidence, 'usage' => $result->usage, 'applied' => $apply, 'error_category' => $apply ? null : 'superseded', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);
                Audit::record('extraction_completed', null, $document, ['run_id' => $current->id, 'applied' => $apply, 'before' => $before, 'after' => $document->extractionFields(), 'result' => $fields, 'confidence' => $result->confidence, 'revision_before' => $revision, 'revision_after' => $document->revision]);
            });

            return null;
        } catch (\Throwable $e) {
            $category = $e instanceof ExtractionFailure ? $e->category : ($e instanceof ValidationException ? 'invalid_result' : 'internal_error');
            $retry = $e instanceof ExtractionFailure && $e->retryable && $run->attempts < config()->integer('ai.max_attempts');
            $delay = $run->attempts === 1 ? 10 : 30;
            $changed = DB::transaction(function () use ($run, $owner, $retry, $delay, $category): int {
                $changed = AiRun::whereKey($run->id)->where('lease_owner', $owner)->where('status', RunStatus::Running)->update([
                    'status' => $retry ? RunStatus::Queued->value : RunStatus::Failed->value, 'error_category' => $category,
                    'available_at' => now()->addSeconds($delay), 'finished_at' => $retry ? null : now(), 'lease_owner' => null, 'lease_until' => null,
                ]);
                if ($changed) {
                    Audit::record($retry ? 'extraction_retry_scheduled' : 'extraction_failed', null, $run->document, ['run_id' => $run->id, 'attempt' => $run->attempts, 'category' => $category]);
                }

                return $changed;
            });

            return $retry && $changed ? $delay : null;
        }
    }
}
