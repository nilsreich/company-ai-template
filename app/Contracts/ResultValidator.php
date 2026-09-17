<?php

namespace App\Contracts;

/**
 * Validates a task payload produced by AI or human input.
 *
 * Domain modules (see examples/invoice-extraction) provide strict
 * implementations; the core default only guards the container shape.
 */
interface ResultValidator
{
    /** @return array<string, mixed> */
    public function handle(mixed $data): array;
}
