<?php

namespace App\Ai\Drivers;

use App\Ai\TaskFailure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\AzureOpenAiProvider;

final class AzureOpenAiDriver implements LlmDriver
{
    public function provider(): TextProvider
    {
        $key = config()->string('ai.azure_key') !== '' ? config()->string('ai.azure_key') : config()->string('ai.key');
        $url = config()->string('ai.azure_url');
        if ($key === '' || ! str_starts_with($url, 'https://')) {
            throw new TaskFailure('configuration');
        }

        return new AzureOpenAiProvider([
            'key' => $key, 'url' => rtrim($url, '/'),
            'api_version' => config()->string('ai.azure_api_version', '2025-04-01-preview'),
            'deployment' => config()->string('ai.model'), 'store' => false,
        ], app(Dispatcher::class));
    }
}
