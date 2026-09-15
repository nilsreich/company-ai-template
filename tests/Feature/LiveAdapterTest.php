<?php

namespace Tests\Feature;

use App\Ai\Agents\InvoiceExtraction;
use App\Ai\ExtractionFailure;
use App\Ai\ExtractionInput;
use App\Ai\OpenAiDocumentExtractor;
use App\Ai\ValidateExtraction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Events\PromptingAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiveAdapterTest extends TestCase
{
    public function test_live_adapter_requests_schema_without_tools_and_validates_usage(): void
    {
        config(['ai.key' => 'fixture-only']);
        $sdkInvocations = 0;
        Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$sdkInvocations): void {
            $this->assertInstanceOf(InvoiceExtraction::class, $event->prompt->agent);
            $sdkInvocations++;
        });
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($this->fields())]]]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 10]])]);
        $result = app(OpenAiDocumentExtractor::class)->extract(new ExtractionInput('Untrusted text', 1, 'fixture-model', 'invoice-v1'));
        $this->assertSame($this->fields(), $result->fields);
        $this->assertSame(5, $result->usage['input_tokens']);
        Http::assertSent(fn ($request) => $request['model'] === 'fixture-model' && $request['store'] === false && ! isset($request['tools']) && $request['text']['format']['strict'] === true);
        Http::assertSentCount(1);
        $this->assertSame(1, $sdkInvocations);
    }

    public function test_http_timeout_is_not_retried_inside_the_adapter(): void
    {
        config(['ai.key' => 'fixture-only']);
        $calls = 0;
        Http::fake(function ($request, array $options) use (&$calls) {
            $calls++;
            $this->assertSame(30, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            throw new ConnectionException('fixture-only confidential error');
        });
        try {
            app(OpenAiDocumentExtractor::class)->extract(new ExtractionInput('text', 1, 'fixture', 'v1'));
            $this->fail('Expected timeout');
        } catch (ExtractionFailure $e) {
            $this->assertSame('timeout', $e->category);
            $this->assertTrue($e->retryable);
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(1, $calls);
    }

    public static function invalidResponses(): array
    {
        return [[429, [], 'rate_limit'], [503, [], 'provider_unavailable'], [500, [], 'provider_unavailable'], [401, [], 'provider_rejected'], [402, [], 'provider_rejected'], [200, ['status' => 'incomplete'], 'incomplete'], [200, ['status' => 'completed', 'output' => [['content' => [['type' => 'refusal']]]]], 'refused'], [200, ['status' => 'completed', 'output' => []], 'invalid_result'], [200, ['status' => 'completed', 'output' => 'secret'], 'invalid_result']];
    }

    #[DataProvider('invalidResponses')]
    public function test_response_failures_are_sanitized(int $status, array $body, string $category): void
    {
        config(['ai.key' => 'fixture-only']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($body, $status)]);
        try {
            app(OpenAiDocumentExtractor::class)->extract(new ExtractionInput('secret', 1, 'fixture', 'v1'));
            $this->fail('Expected failure');
        } catch (ExtractionFailure $e) {
            $this->assertSame($category, $e->category);
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertSame(in_array($status, [429, 500, 503], true), $e->retryable);
        }
        Http::assertSentCount(1);
    }

    public static function invalidFields(): array
    {
        return [[['supplier' => null]], [['total_amount' => 1.2]], [['total_amount' => '1e9']], [['total_amount' => '1.001']], [['currency' => 'XYZ']], [['invoice_date' => '2026-02-30']], [['invoice_number' => '']], [['unknown' => 'value']]];
    }

    #[DataProvider('invalidFields')]
    public function test_sdk_response_still_passes_through_business_validation(array $changes): void
    {
        config(['ai.key' => 'fixture-only']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([...$this->fields(), ...$changes])]]]],
        ])]);
        try {
            app(OpenAiDocumentExtractor::class)->extract(new ExtractionInput('secret', 1, 'fixture', 'v1'));
            $this->fail('Invalid SDK response accepted');
        } catch (ExtractionFailure $e) {
            $this->assertSame('invalid_result', $e->category);
            $this->assertFalse($e->retryable);
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_extracted_fields_are_rejected(array $changes): void
    {
        $this->expectException(ValidationException::class);
        app(ValidateExtraction::class)->handle([...$this->fields(), ...$changes]);
    }
}
