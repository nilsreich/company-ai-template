<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

class TelescopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }
        $this->loadMigrationsFrom(database_path('migrations/local'));
        Telescope::filter(function (IncomingEntry $entry): bool {
            // An allowlist prevents new SDK/package watchers from leaking payloads.
            if (! app()->environment('local') || ! in_array($entry->type, ['request', 'query'], true)) {
                return false;
            }
            $entry->tags = [];
            $entry->user = null;
            $entry->content = match ($entry->type) {
                'request' => Arr::only($entry->content, ['method', 'response_status', 'duration', 'memory', 'controller_action', 'middleware']) + [
                    'uri' => '/'.(request()->route()?->uri() ?? '[unmatched]'),
                    'headers' => [], 'payload' => [], 'session' => [], 'response_headers' => [], 'response' => '[Inhalt ausgeblendet]',
                ],
                'query' => Arr::only($entry->content, ['connection', 'driver', 'time', 'slow', 'file', 'line', 'hash']) + [
                    'sql' => '[SQL und Bindings ausgeblendet]', 'bindings' => [],
                ],
            };

            return true;
        });
    }

    public function boot(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }
        Gate::define('viewTelescope', fn (User $user): bool => Gate::forUser($user)->allows('viewAny', User::class));
        Telescope::auth(fn (): bool => app()->environment('local') && Gate::allows('viewTelescope'));
    }
}
