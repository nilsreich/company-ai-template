<?php

namespace App\Ai;

final readonly class TaskInput
{
    public function __construct(public string $text, public int $attempt, public string $model, public string $promptVersion, public string $scenario = 'success', public string $mimeType = 'text/plain') {}
}
