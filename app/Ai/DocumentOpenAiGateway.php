<?php

namespace App\Ai;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\Provider;

/** HTTP safeguards missing from the SDK's default transport configuration. */
final class DocumentOpenAiGateway extends OpenAiGateway
{
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return parent::client($provider, $timeout)->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    /** @param array<string, mixed> $data */
    protected function validateTextResponse(array $data): void
    {
        if (($data['status'] ?? null) !== 'completed') {
            throw new ExtractionFailure('incomplete');
        }
        if (isset($data['error']) || ! is_array($data['output'] ?? null)) {
            throw new ExtractionFailure('invalid_result');
        }
        foreach ($data['output'] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'reasoning') {
                continue;
            }
            if (! is_array($item) || ! is_array($item['content'] ?? null)) {
                throw new ExtractionFailure('invalid_result');
            }
            foreach ($item['content'] as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'refusal') {
                    throw new ExtractionFailure('refused');
                }
            }
        }
    }
}
