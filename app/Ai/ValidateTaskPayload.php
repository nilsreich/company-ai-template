<?php

namespace App\Ai;

use App\Contracts\ResultValidator;
use Illuminate\Validation\ValidationException;

final class ValidateTaskPayload implements ResultValidator
{
    /** @return array<string, mixed> */
    public function handle(mixed $data): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages(['result' => 'Ein Ergebnisobjekt wird benötigt.']);
        }
        if ($data === [] || count($data) > 50) {
            throw ValidationException::withMessages(['result' => 'Das Ergebnis benötigt 1 bis 50 Felder.']);
        }
        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                throw ValidationException::withMessages(['result' => 'Unzulässiger Feldname.']);
            }
            if ($value !== null && ! is_scalar($value)) {
                throw ValidationException::withMessages([$key => 'Nur Text, Zahlen oder leere Werte sind zulässig.']);
            }
        }

        /** @var array<string, bool|float|int|string|null> $data */
        return $data;
    }
}
