<?php

namespace App\Ai;

final readonly class TaskResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>|null  $usage
     * @param  array<string, float|int>  $confidence
     */
    public function __construct(public array $payload, public ?array $usage = null, public array $confidence = []) {}
}
