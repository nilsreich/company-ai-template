<?php

use App\Ai\Agents\GeneralTaskAgent;
use App\Ai\ValidateTaskPayload;

return [
    'driver' => env('AI_DRIVER', 'fake'),
    'key' => env('AI_API_KEY', ''),
    'model' => env('AI_MODEL', 'gpt-4.1-mini-2025-04-14'),
    'url' => env('AI_URL', 'https://api.openai.com/v1/responses'),
    'timeout' => (int) env('AI_TIMEOUT', 30),
    'live_provider' => env('AI_LIVE_PROVIDER', 'openai'),
    'azure_key' => env('AI_AZURE_KEY', ''),
    'azure_url' => env('AI_AZURE_URL', ''),
    'azure_api_version' => env('AI_AZURE_API_VERSION', '2025-04-01-preview'),
    'ollama_key' => env('AI_OLLAMA_KEY', ''),
    'ollama_url' => env('AI_OLLAMA_URL', 'http://localhost:11434'),
    'job_timeout' => 60,
    'lease_seconds' => 90,
    'max_attempts' => 3,
    'prompt_version' => 'task-v1',
    'agent' => env('AI_AGENT', GeneralTaskAgent::class),
    'validator' => env('AI_VALIDATOR', ValidateTaskPayload::class),
    'fake_scenario' => env('AI_FAKE_SCENARIO', 'success'),
    'fake_delay' => (int) env('AI_FAKE_DELAY', 8),
];
