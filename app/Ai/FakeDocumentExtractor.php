<?php

namespace App\Ai;

final class FakeDocumentExtractor implements DocumentExtractor
{
    public function extract(ExtractionInput $input): ExtractionResult
    {
        $delay = config()->integer('ai.fake_delay');
        if ($delay > 0) {
            sleep($delay);
        }
        if ($input->scenario === 'timeout') {
            throw new ExtractionFailure('timeout', true);
        }
        if ($input->scenario === 'rate_limit' && $input->attempt === 1) {
            throw new ExtractionFailure('rate_limit', true);
        }
        if ($input->scenario === 'invalid') {
            return new ExtractionResult(['supplier' => 'Ungültig']);
        }

        $fields = [
            'supplier' => 'Musterlieferant GmbH', 'invoice_number' => 'DEMO-'.strtoupper(substr(hash('sha256', $input->text), 0, 8)),
            'invoice_date' => '2026-01-15', 'total_amount' => '123.45', 'currency' => 'EUR',
            'net_amount' => '103.74', 'tax_amount' => '19.71', 'iban' => 'DE89370400440532013000',
        ];

        return new ExtractionResult($fields, ['input_tokens' => 100, 'output_tokens' => 40], array_fill_keys(array_keys($fields), 0.95));
    }
}
