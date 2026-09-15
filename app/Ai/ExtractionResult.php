<?php

namespace App\Ai;

final readonly class ExtractionResult
{
    /**
     * @param  array<string, string>  $fields
     * @param  array<string, int>|null  $usage
     */
    public function __construct(public array $fields, public ?array $usage = null) {}
}
