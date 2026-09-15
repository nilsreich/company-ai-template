<?php

namespace App\Ai;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Intl\Currencies;

final class ValidateExtraction
{
    /** @return array{supplier: string, invoice_number: string, invoice_date: string, total_amount: string, currency: string} */
    public function handle(mixed $data): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages(['result' => 'Ein Ergebnisobjekt wird benötigt.']);
        }
        $fields = ['supplier', 'invoice_number', 'invoice_date', 'total_amount', 'currency'];
        if (array_diff(array_keys($data), $fields) !== []) {
            throw ValidationException::withMessages(['result' => 'Unbekannte Ergebnisfelder.']);
        }
        Validator::make($data, [
            'supplier' => ['required', 'string', 'max:255'],
            'invoice_number' => ['required', 'string', 'max:120'],
            'invoice_date' => ['required', 'string', 'date_format:Y-m-d'],
            'total_amount' => ['required', 'string', 'regex:/^-?(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?$/D'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
        ])->validate();
        if (! Currencies::exists($data['currency'])) {
            throw ValidationException::withMessages(['currency' => 'Unbekannte ISO-Währung.']);
        }
        $fraction = rtrim(explode('.', $data['total_amount'])[1] ?? '', '0');
        if (strlen($fraction) > Currencies::getFractionDigits($data['currency'])) {
            throw ValidationException::withMessages(['total_amount' => 'Zu viele Nachkommastellen für diese Währung.']);
        }

        /** @var array{supplier: string, invoice_number: string, invoice_date: string, total_amount: string, currency: string} $data */
        return $data;
    }
}
