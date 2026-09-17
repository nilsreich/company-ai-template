<?php

namespace App\Providers;

use App\Ai\FakeTaskExtractor;
use App\Ai\LiveTaskExtractor;
use App\Ai\LlmProviderFactory;
use App\Ai\TaskExtractor;
use App\Ai\ValidateTaskPayload;
use App\Contracts\ResultValidator;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->bind(TaskExtractor::class, fn () => match (config('ai.driver')) {
            'fake' => new FakeTaskExtractor, 'live' => new LiveTaskExtractor, default => throw new \LogicException('AI_DRIVER muss fake oder live sein.'),
        });
        $this->app->bind(ResultValidator::class, fn () => new (config()->string('ai.validator', ValidateTaskPayload::class)));
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', Provider::class);
        });
        Livewire::addPersistentMiddleware([EnsureActiveUser::class]);
        if (! in_array(config()->string('ai.live_provider', 'openai'), LlmProviderFactory::PROVIDERS, true)) {
            throw new \LogicException('AI_LIVE_PROVIDER muss openai, azure oder ollama sein.');
        }
        if (config()->integer('ai.timeout') < 1 || config()->integer('ai.timeout') >= config()->integer('ai.job_timeout') || config()->integer('ai.job_timeout') >= config()->integer('ai.lease_seconds') || config()->integer('ai.lease_seconds') >= (int) config('queue.connections.database.retry_after')) {
            throw new \LogicException('Erforderlich: 0 < AI_TIMEOUT < Job-Timeout < Lease < retry_after.');
        }
    }
}
