<?php

namespace App\Ai\Drivers;

use App\Ai\OpenAiResponseGateway;
use App\Ai\TaskFailure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\OpenAiProvider;

final class OpenAiDriver implements LlmDriver
{
    public function provider(): TextProvider
    {
        if (config()->string('ai.key') === '') {
            throw new TaskFailure('configuration');
        }
        $url = config()->string('ai.url');
        if (! str_ends_with($url, '/responses') || ! str_starts_with($url, 'https://')) {
            throw new TaskFailure('configuration');
        }

        // AI_URL retains the existing full Responses endpoint contract.
        return new OpenAiProvider(app(OpenAiResponseGateway::class), [
            'name' => 'openai', 'driver' => 'openai', 'key' => config()->string('ai.key'),
            'url' => substr($url, 0, -strlen('/responses')), 'store' => false,
        ], app(Dispatcher::class));
    }
}
