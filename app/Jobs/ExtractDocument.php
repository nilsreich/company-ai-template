<?php

namespace App\Jobs;

use App\Actions\ProcessExtraction;
use App\Enums\RunStatus;
use App\Models\AiRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExtractDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $runId) {}

    public function handle(ProcessExtraction $process): void
    {
        $delay = $process->handle($this->runId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        AiRun::whereKey($this->runId)->whereIn('status', [RunStatus::Queued, RunStatus::Running])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update(['status' => RunStatus::Failed->value, 'error_category' => 'worker_interrupted', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);
    }
}
