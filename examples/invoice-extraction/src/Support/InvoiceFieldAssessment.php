<?php

namespace Examples\InvoiceExtraction\Support;

use Examples\InvoiceExtraction\Agents\InvoiceExtraction;
use Examples\InvoiceExtraction\Validation\InvoiceValidator;
use Illuminate\Validation\ValidationException;

final class InvoiceFieldAssessment
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, int|float>  $confidence
     * @param  array<string, mixed>  $original
     * @return array<string, array{color: string, label: string, reason: string, confidence: int|float|null}>
     */
    public function assess(array $fields, array $confidence = [], array $original = []): array
    {
        $errors = [];
        try {
            app(InvoiceValidator::class)->handle($fields);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
        $math = false;
        if (! isset($errors['total_amount'], $errors['net_amount'], $errors['tax_amount']) && $this->amount($fields['total_amount'] ?? null) && $this->amount($fields['net_amount'] ?? null) && $this->amount($fields['tax_amount'] ?? null)) {
            $math = bccomp(bcadd($fields['net_amount'], $fields['tax_amount'], 4), $fields['total_amount'], 4) === 0;
            if (! $math) {
                foreach (['net_amount', 'tax_amount', 'total_amount'] as $field) {
                    $errors[$field] = ['Netto + Umsatzsteuer stimmt nicht mit Brutto überein.'];
                }
            }
        }
        $iban = $fields['iban'] ?? null;
        if (is_string($iban) && $iban !== '' && ! $this->validIbanChecksum($iban)) {
            $errors['iban'] = ['IBAN-Prüfziffer ungültig.'];
        }
        $result = [];
        foreach (InvoiceExtraction::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            $same = $value === ($original[$field] ?? null) || ($this->amount($value) && $this->amount($original[$field] ?? null) && bccomp($value, $original[$field], 4) === 0);
            $score = $same ? ($confidence[$field] ?? null) : null;
            $verified = ($field === 'iban' && is_string($iban) && $this->validIbanChecksum($iban)) || ($math && in_array($field, ['net_amount', 'tax_amount', 'total_amount'], true));
            $color = isset($errors[$field]) ? 'danger' : ($verified && ($score === null || $score >= 0.8) ? 'success' : 'warning');
            $reason = $errors[$field][0] ?? ($verified ? ($field === 'iban' ? 'Mod-97-Prüfziffer stimmt; Kontoinhaber nicht geprüft.' : 'Netto + Umsatzsteuer = Brutto; Belegabgleich bleibt erforderlich.') : 'Inhalt am Original prüfen.');
            if ($score !== null && $score < 0.8 && ! isset($errors[$field])) {
                $reason = 'Niedrige KI-Selbsteinschätzung; bitte am Original prüfen. '.$reason;
            }
            $result[$field] = ['color' => $color, 'label' => match ($color) {
                'danger' => 'Fehler', 'success' => 'Rechnerisch geprüft', default => 'Prüfen'
            }, 'reason' => $reason, 'confidence' => $score];
        }

        return $result;
    }

    /** @param array<string, mixed> $fields */
    public function assertApprovable(array $fields): void
    {
        $errors = [];
        foreach ($this->assess($fields) as $field => $assessment) {
            if ($assessment['color'] === 'danger') {
                $errors[$field] = $assessment['reason'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function validIbanChecksum(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
            return false;
        }
        $remainder = 0;
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    private function amount(mixed $value): bool
    {
        return is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?$/D', $value) === 1;
    }
}
