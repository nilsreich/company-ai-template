<?php

namespace App\Ai;

use RuntimeException;

final class TaskFailure extends RuntimeException
{
    public function __construct(public readonly string $category, public readonly bool $retryable = false)
    {
        parent::__construct('Extraktion fehlgeschlagen: '.$category);
    }
}
