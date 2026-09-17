<?php

namespace App\Console\Commands;

use App\Actions\StartExecution;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecoverExecutions extends Command
{
    protected $signature = 'ai:recover';

    protected $description = 'Re-enqueue expired executions and committed dispatch intents';

    public function handle(StartExecution $start): int
    {
        $lock = Cache::lock('ai-recovery', 55);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            Execution::whereIn('status', [ExecutionStatus::Queued, ExecutionStatus::Running])
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subSeconds(180)))
                ->eachById(fn (Execution $execution) => $start->dispatch($execution));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
