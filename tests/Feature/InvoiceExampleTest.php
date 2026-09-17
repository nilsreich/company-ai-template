<?php

namespace Tests\Feature;

use App\Actions\ApproveTask;
use App\Actions\CorrectTask;
use App\Actions\ProcessExecution;
use App\Ai\TaskExtractor;
use App\Ai\TaskResult;
use App\Contracts\ResultValidator;
use App\Enums\Role;
use App\Models\User;
use Examples\InvoiceExtraction\Agents\InvoiceExtraction;
use Examples\InvoiceExtraction\Export\InvoiceCsvExporter;
use Examples\InvoiceExtraction\Support\InvoiceFieldAssessment;
use Examples\InvoiceExtraction\Validation\InvoiceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceExampleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string|null> */
    private function invoice(): array
    {
        return ['supplier' => 'Korrigiert GmbH', 'invoice_number' => 'R-123', 'invoice_date' => '2026-09-01', 'total_amount' => '99.95', 'currency' => 'EUR', 'net_amount' => '83.99', 'tax_amount' => '15.96', 'iban' => null];
    }

    public static function invalidInvoices(): array
    {
        return [[['supplier' => null]], [['total_amount' => 1.2]], [['total_amount' => '1e9']], [['total_amount' => '1.001']], [['currency' => 'XYZ']], [['invoice_date' => '2026-02-30']], [['invoice_number' => '']], [['unknown' => 'value']]];
    }

    #[DataProvider('invalidInvoices')]
    public function test_invoice_validator_rejects_bad_fields(array $changes): void
    {
        $this->expectException(ValidationException::class);
        app(InvoiceValidator::class)->handle([...$this->invoice(), ...$changes]);
    }

    public function test_invoice_module_runs_through_the_generic_flow(): void
    {
        config(['ai.validator' => InvoiceValidator::class, 'ai.agent' => InvoiceExtraction::class]);
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $task = $this->upload($user);
        $this->mock(TaskExtractor::class)->shouldReceive('extract')->once()->andReturn(new TaskResult($this->invoice()));
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        $this->assertSame('Korrigiert GmbH', $task->refresh()->payload()['supplier']);

        $this->assertInstanceOf(ResultValidator::class, app(ResultValidator::class));
        try {
            app(CorrectTask::class)->handle($user, $task, $task->revision, [...$this->invoice(), 'currency' => 'ZZZ']);
            $this->fail('Expected currency rejection');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_invoice_assessment_flags_broken_math(): void
    {
        $assessment = app(InvoiceFieldAssessment::class)->assess([...$this->invoice(), 'total_amount' => '1.00']);

        $this->assertSame('danger', $assessment['total_amount']['color']);
    }

    public function test_invoice_csv_neutralizes_formulas(): void
    {
        config(['ai.validator' => InvoiceValidator::class]);
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $task = $this->upload($user);
        $task = app(CorrectTask::class)->handle($user, $task, 0, [...$this->invoice(), 'supplier' => ' =HYPERLINK("bad")', 'invoice_number' => '@SUM(1)']);
        app(ApproveTask::class)->handle($user, $task, $task->revision);
        $csv = app(InvoiceCsvExporter::class)->handle($user, $task);
        $this->assertStringContainsString("' =HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
    }
}
