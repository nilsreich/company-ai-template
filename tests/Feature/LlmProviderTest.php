<?php

namespace Tests\Feature;

use App\Ai\Drivers\AzureOpenAiDriver;
use App\Ai\Drivers\LlmDriver;
use App\Ai\Drivers\OllamaDriver;
use App\Ai\Drivers\OpenAiDriver;
use App\Ai\LlmProviderFactory;
use App\Ai\TaskFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Providers\AzureOpenAiProvider;
use Laravel\Ai\Providers\OllamaProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

class LlmProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_builds_the_configured_provider(): void
    {
        config(['ai.key' => 'fixture-only', 'ai.url' => 'https://api.openai.com/v1/responses']);
        $this->assertInstanceOf(OpenAiProvider::class, app(LlmProviderFactory::class)->make('openai'));

        config(['ai.azure_key' => 'azure-fixture', 'ai.azure_url' => 'https://example.openai.azure.com']);
        $this->assertInstanceOf(AzureOpenAiProvider::class, app(LlmProviderFactory::class)->make('azure'));

        config(['ai.ollama_url' => 'http://localhost:11434']);
        $this->assertInstanceOf(OllamaProvider::class, app(LlmProviderFactory::class)->make('ollama'));
    }

    public function test_drivers_expose_a_common_interface(): void
    {
        $this->assertInstanceOf(LlmDriver::class, app(LlmProviderFactory::class)->driver('openai'));
        $this->assertInstanceOf(OpenAiDriver::class, app(LlmProviderFactory::class)->driver('openai'));
        $this->assertInstanceOf(AzureOpenAiDriver::class, app(LlmProviderFactory::class)->driver('azure'));
        $this->assertInstanceOf(OllamaDriver::class, app(LlmProviderFactory::class)->driver('ollama'));
    }

    public function test_factory_rejects_unknown_providers_and_missing_credentials(): void
    {
        try {
            app(LlmProviderFactory::class)->make('unknown');
            $this->fail('Expected configuration failure');
        } catch (TaskFailure $e) {
            $this->assertSame('configuration', $e->category);
        }

        config(['ai.key' => '']);
        try {
            app(LlmProviderFactory::class)->make('openai');
            $this->fail('Expected configuration failure');
        } catch (TaskFailure $e) {
            $this->assertSame('configuration', $e->category);
        }

        config(['ai.azure_key' => '', 'ai.key' => '', 'ai.azure_url' => 'https://example.openai.azure.com']);
        try {
            app(LlmProviderFactory::class)->make('azure');
            $this->fail('Expected configuration failure');
        } catch (TaskFailure $e) {
            $this->assertSame('configuration', $e->category);
        }

        config(['ai.ollama_url' => 'not-a-url']);
        try {
            app(LlmProviderFactory::class)->make('ollama');
            $this->fail('Expected configuration failure');
        } catch (TaskFailure $e) {
            $this->assertSame('configuration', $e->category);
        }
    }
}
