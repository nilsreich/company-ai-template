<?php

namespace App\Ai\Drivers;

use App\Ai\TaskFailure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\OllamaProvider;

final class OllamaDriver implements LlmDriver
{
    public function provider(): TextProvider
    {
        // Local models usually need no key; the SDK only sends Bearer when filled.
        $url = config()->string('ai.ollama_url', 'http://localhost:11434');
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            throw new TaskFailure('configuration');
        }

        return new OllamaProvider([
            'key' => config()->string('ai.ollama_key') !== '' ? config()->string('ai.ollama_key') : config()->string('ai.key'),
            'url' => rtrim($url, '/'), 'store' => false,
        ], app(Dispatcher::class));
    }
}
