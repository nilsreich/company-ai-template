<?php

namespace App\Ai;

use App\Ai\Drivers\AzureOpenAiDriver;
use App\Ai\Drivers\LlmDriver;
use App\Ai\Drivers\OllamaDriver;
use App\Ai\Drivers\OpenAiDriver;
use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * Resolves the single configured live driver.
 *
 * Select via `AI_LIVE_PROVIDER` (openai, azure, ollama).
 */
final class LlmProviderFactory
{
    public const PROVIDERS = ['openai', 'azure', 'ollama'];

    public function make(?string $provider = null): TextProvider
    {
        return $this->driver($provider ?? config()->string('ai.live_provider', 'openai'))->provider();
    }

    public function driver(string $name): LlmDriver
    {
        return match ($name) {
            'openai' => app(OpenAiDriver::class),
            'azure' => app(AzureOpenAiDriver::class),
            'ollama' => app(OllamaDriver::class),
            default => throw new TaskFailure('configuration'),
        };
    }
}
