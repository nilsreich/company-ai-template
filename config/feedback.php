<?php

return [
    'repository' => env('FEEDBACK_GITHUB_REPOSITORY', ''),
    'token' => env('FEEDBACK_GITHUB_TOKEN', ''),
    'build' => env('APP_BUILD', 'unknown'),
    'plan' => env('APP_PLAN', 'unknown'),
];
