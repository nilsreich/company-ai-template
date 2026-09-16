<?php

namespace App\Ai;

final readonly class ExtractionResult
{
    /**
     * @param  array<string, string|null>  $fields
     * @param  array<string, int>|null  $usage
     * @param  array<string, float|int>  $confidence
     */
    public function __construct(public array $fields, public ?array $usage = null, public array $confidence = []) {}
}
