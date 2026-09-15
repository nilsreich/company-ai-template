<?php

namespace App\Console\Commands;

use App\Actions\StartExtraction;
use App\Enums\RunStatus;
use App\Models\AiRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecoverAiRuns extends Command
{
    protected $signature = 'ai:recover';

    protected $description = 'Re-enqueue expired runs and committed dispatch intents';

    public function handle(StartExtraction $start): int
    {
        $lock = Cache::lock('ai-recovery', 55);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            AiRun::whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subSeconds(180)))
                ->eachById(fn (AiRun $run) => $start->dispatch($run));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
