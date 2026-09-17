<?php

namespace App\Jobs;

use App\Actions\ProcessExecution;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunExecution implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $executionId) {}

    public function handle(ProcessExecution $process): void
    {
        $delay = $process->handle($this->executionId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Execution::whereKey($this->executionId)->whereIn('status', [ExecutionStatus::Queued, ExecutionStatus::Running])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update(['status' => ExecutionStatus::Failed->value, 'error_category' => 'worker_interrupted', 'finished_at' => now(), 'lease_owner' => null, 'lease_until' => null]);
    }
}
