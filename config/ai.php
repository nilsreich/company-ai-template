<?php

return [
    'driver' => env('AI_DRIVER', 'fake'),
    'key' => env('AI_API_KEY', ''),
    'model' => env('AI_MODEL', 'gpt-4.1-mini-2025-04-14'),
    'url' => env('AI_URL', 'https://api.openai.com/v1/responses'),
    'timeout' => (int) env('AI_TIMEOUT', 30),
    'job_timeout' => 60,
    'lease_seconds' => 90,
    'max_attempts' => 3,
    'prompt_version' => 'invoice-v1',
    'fake_scenario' => env('AI_FAKE_SCENARIO', 'success'),
    'fake_delay' => (int) env('AI_FAKE_DELAY', 8),
];
