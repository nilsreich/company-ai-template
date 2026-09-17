<?php

namespace App\Ai\Drivers;

use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * Single configured live SDK provider behind the domain boundary.
 *
 * One driver per extraction, no failover, no SDK queue.
 */
interface LlmDriver
{
    public function provider(): TextProvider;
}
