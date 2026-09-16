<?php

namespace App\Ai;

use App\Ai\Agents\InvoiceExtraction;
use App\Models\Document;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class OpenAiDocumentExtractor implements DocumentExtractor
{
    public function extract(ExtractionInput $input): ExtractionResult
    {
        if (config()->string('ai.key') === '') {
            throw new ExtractionFailure('configuration');
        }
        $url = config()->string('ai.url');
        if (! str_ends_with($url, '/responses') || ! str_starts_with($url, 'https://')) {
            throw new ExtractionFailure('configuration');
        }

        // Fresh SDK provider per invocation: no failover or SDK queue.
        // AI_URL retains the existing full Responses endpoint contract.
        $provider = new OpenAiProvider(app(DocumentOpenAiGateway::class), [
            'name' => 'openai', 'driver' => 'openai', 'key' => config()->string('ai.key'),
            'url' => substr($url, 0, -strlen('/responses')), 'store' => false,
        ], app(Dispatcher::class));
        try {
            $response = $provider->prompt(new AgentPrompt(
                new InvoiceExtraction($input->promptVersion),
                $input->mimeType === 'application/pdf' ? 'Extract the invoice from the attached PDF.' : $input->text,
                $input->mimeType === 'application/pdf' ? [AiDocument::fromString($input->text, 'application/pdf')->as('document.pdf')] : [],
                $provider, $input->model, config()->integer('ai.timeout'),
            ));
        } catch (ProviderConnectionException) {
            throw new ExtractionFailure('timeout', true);
        } catch (RateLimitedException) {
            throw new ExtractionFailure('rate_limit', true);
        } catch (ProviderOverloadedException) {
            throw new ExtractionFailure('provider_unavailable', true);
        } catch (RequestException $exception) {
            throw new ExtractionFailure($exception->response->serverError() ? 'provider_unavailable' : 'provider_rejected', $exception->response->serverError());
        } catch (AiException) {
            throw new ExtractionFailure('provider_rejected');
        } catch (\TypeError|\JsonException|\ErrorException) {
            throw new ExtractionFailure('invalid_result');
        }

        if (! $response instanceof StructuredAgentResponse || ! $response->raw?->successful()) {
            throw new ExtractionFailure('invalid_result');
        }
        try {
            $data = $response->toArray();
            $confidence = $data['confidence'] ?? [];
            unset($data['confidence']);
            Validator::make(['confidence' => $confidence], [
                'confidence' => ['array:'.implode(',', Document::FIELDS)],
                'confidence.*' => ['numeric', 'min:0', 'max:1'],
            ])->validate();
            $confidence = array_map(static fn ($value): float => (float) $value, $confidence);
            $fields = app(ValidateExtraction::class)->handle($data);
        } catch (ValidationException) {
            throw new ExtractionFailure('invalid_result');
        }
        $usage = [];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            $value = $response->raw->json('usage.'.$key);
            if (is_int($value) && $value >= 0) {
                $usage[$key] = $value;
            }
        }

        return new ExtractionResult($fields, $usage ?: null, $confidence);
    }
}
