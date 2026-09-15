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

                return null;
            }
            $run->update(['status' => RunStatus::Running, 'attempts' => $run->attempts + 1, 'started_at' => now(), 'lease_owner' => $owner, 'lease_until' => now()->addSeconds(config()->integer('ai.lease_seconds')), 'error_category' => null]);

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
            $result = $extractor->extract(new ExtractionInput($text, $run->attempts, $run->model, $run->prompt_version, $run->fake_scenario));
            $fields = $this->validate->handle($result->fields);
            DB::transaction(function () use ($run, $owner, $fields, $result): void {
                $document = Document::query()->lockForUpdate()->findOrFail($run->document_id);
                $current = AiRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($current->lease_owner !== $owner || ! $current->lease_until?->isFuture() || $current->status !== RunStatus::Running) {
                    return;
                }
                $apply = $document->input_version === $run->input_version && $document->revision === $run->document_revision && $document->status !== DocumentStatus::Approved;
                if ($apply) {
                    $document->update([...$fields, 'revision' => $document->revision + 1, 'status' => DocumentStatus::InReview]);
                }
                $current->update(['status' => RunStatus::Succeeded, 'result' => $fields, 'usage' => $result->usage, 'applied' => $apply, 'error_category' => $apply ? null : 'superseded', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);
            });

            return null;
        } catch (\Throwable $e) {
            $category = $e instanceof ExtractionFailure ? $e->category : ($e instanceof ValidationException ? 'invalid_result' : 'internal_error');
            $retry = $e instanceof ExtractionFailure && $e->retryable && $run->attempts < config()->integer('ai.max_attempts');
            $delay = $run->attempts === 1 ? 10 : 30;
            $changed = AiRun::whereKey($run->id)->where('lease_owner', $owner)->where('status', RunStatus::Running)->update([
                'status' => $retry ? RunStatus::Queued->value : RunStatus::Failed->value, 'error_category' => $category,
                'available_at' => now()->addSeconds($delay), 'finished_at' => $retry ? null : now(), 'lease_owner' => null, 'lease_until' => null,
            ]);

            return $retry && $changed ? $delay : null;
        }
    }
}
