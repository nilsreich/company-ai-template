<?php

namespace App\Ai;

use RuntimeException;

final class ExtractionFailure extends RuntimeException
{
    public function __construct(public readonly string $category, public readonly bool $retryable = false)
    {
        parent::__construct('Extraktion fehlgeschlagen: '.$category);
    }
}
