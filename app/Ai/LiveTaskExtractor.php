<?php

namespace App\Ai;

use App\Ai\Agents\GeneralTaskAgent;
use App\Contracts\ResultValidator;
use App\Contracts\TaskAgent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StructuredAgentResponse;

/** Live extractor behind the TaskExtractor boundary; provider chosen by LlmProviderFactory. */
final class LiveTaskExtractor implements TaskExtractor
{
    public function extract(TaskInput $input): TaskResult
    {
        // Fresh SDK provider per invocation: no failover or SDK queue.
        $provider = app(LlmProviderFactory::class)->make();
        $agentClass = config()->string('ai.agent', GeneralTaskAgent::class);
        if (! is_a($agentClass, TaskAgent::class, true)) {
            throw new TaskFailure('configuration');
        }
        $agent = new $agentClass($input->promptVersion);
        try {
            $response = $provider->prompt(new AgentPrompt(
                $agent,
                $input->mimeType === 'application/pdf' ? 'Summarize the attached PDF.' : $input->text,
                $input->mimeType === 'application/pdf' ? [AiDocument::fromString($input->text, 'application/pdf')->as('document.pdf')] : [],
                $provider, $input->model, config()->integer('ai.timeout'),
            ));
        } catch (ProviderConnectionException) {
            throw new TaskFailure('timeout', true);
        } catch (RateLimitedException) {
            throw new TaskFailure('rate_limit', true);
        } catch (ProviderOverloadedException) {
            throw new TaskFailure('provider_unavailable', true);
        } catch (RequestException $exception) {
            throw new TaskFailure($exception->response->serverError() ? 'provider_unavailable' : 'provider_rejected', $exception->response->serverError());
        } catch (AiException) {
            throw new TaskFailure('provider_rejected');
        } catch (\TypeError|\JsonException|\ErrorException) {
            throw new TaskFailure('invalid_result');
        }

        if (! $response instanceof StructuredAgentResponse || ! $response->raw?->successful()) {
            throw new TaskFailure('invalid_result');
        }
        try {
            $data = $response->toArray();
            $confidence = $data['confidence'] ?? [];
            unset($data['confidence']);
            Validator::make(['confidence' => $confidence], [
                'confidence' => ['array'],
                'confidence.*' => ['numeric', 'min:0', 'max:1'],
            ])->validate();
            if (array_diff(array_keys($data), array_keys($confidence)) !== [] && $confidence !== []) {
                throw ValidationException::withMessages(['confidence' => 'Konfidenz unvollständig.']);
            }
            $confidence = array_map(static fn ($value): float => (float) $value, $confidence);
            $payload = app(ResultValidator::class)->handle($data);
        } catch (ValidationException) {
            throw new TaskFailure('invalid_result');
        }
        $usage = [];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            $value = $response->raw->json('usage.'.$key);
            if (is_int($value) && $value >= 0) {
                $usage[$key] = $value;
            }
        }

        return new TaskResult($payload, $usage ?: null, $confidence);
    }
}
