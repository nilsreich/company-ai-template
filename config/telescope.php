<?php

use App\Http\Middleware\EnsureActiveUser;
use Laravel\Telescope\Http\Middleware\Authorize;
use Laravel\Telescope\Watchers;

return [
    'enabled' => env('TELESCOPE_ENABLED', false),
    'domain' => null,
    'path' => 'telescope',
    'driver' => 'database',
    'storage' => ['database' => ['connection' => env('DB_CONNECTION', 'pgsql'), 'chunk' => 1000]],
    'queue' => ['connection' => null, 'queue' => null, 'delay' => 10],
    'middleware' => ['web', 'auth', EnsureActiveUser::class, Authorize::class],
    'only_paths' => [],
    'ignore_paths' => ['auth/*', 'login', '_boost*', 'up', '.well-known*'],
    'ignore_commands' => [],
    // Only timing/route metadata survives the provider filter. No job updates,
    // outgoing HTTP requests, SDK events, exception payloads or model contents.
    'watchers' => [
        Watchers\RequestWatcher::class => ['enabled' => true, 'size_limit' => 0],
        Watchers\QueryWatcher::class => ['enabled' => true, 'ignore_packages' => true, 'slow' => 100],
    ],
];
