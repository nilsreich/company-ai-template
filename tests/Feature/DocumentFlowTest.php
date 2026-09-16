<?php

namespace Tests\Feature;

use App\Actions\ApproveDocument;
use App\Actions\CorrectDocument;
use App\Actions\ExportDocument;
use App\Actions\ProcessExtraction;
use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Jobs\ExtractDocument;
use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_corrections_are_reported_on_the_form_without_writing(): void
    {
        $editor = User::factory()->create();
        $document = $this->upload($editor);
        $this->actingAs($editor);
        Livewire::test(EditDocument::class, ['record' => $document->id])
            ->fillForm([...$this->fields(), 'currency' => 'ZZZ'])
            ->call('save')->assertHasFormErrors(['currency']);
        $this->assertSame(0, $document->refresh()->revision);
        $this->assertNull($document->supplier);
    }

    public function test_invalid_utf8_is_reported_on_the_upload_field(): void
    {
        Storage::fake('private');
        Queue::fake();
        $this->actingAs(User::factory()->create());
        Livewire::test(CreateDocument::class)
            ->fillForm(['file' => UploadedFile::fake()->createWithContent('invalid.txt', "Invalid \xFF UTF-8")])
            ->call('create')->assertHasFormErrors(['file']);
        $this->assertDatabaseCount('documents', 0);
        Queue::assertNothingPushed();
    }

    public function test_complete_filament_flow_with_private_download_and_export(): void
    {
        Storage::fake('private');
        Queue::fake();
        $editor = User::factory()->create();
        $reviewer = User::factory()->create(['role' => Role::Reviewer]);
        $this->actingAs($editor);
        Livewire::test(CreateDocument::class)->fillForm(['file' => UploadedFile::fake()->createWithContent('invoice.txt', 'Rechnung <script>alert(1)</script>')])->call('create')->assertHasNoFormErrors();
        $document = Document::sole();
        Queue::assertPushed(ExtractDocument::class, fn ($job) => $job->runId === $document->runs()->sole()->id);
        Storage::disk('private')->assertExists($document->path);
        app(ProcessExtraction::class)->handle($document->runs()->sole()->id);
        $this->assertSame(DocumentStatus::InReview, $document->refresh()->status);
        Livewire::test(EditDocument::class, ['record' => $document->id])->fillForm($this->fields())->call('save')->assertHasNoFormErrors();
        $this->assertSame('Korrigiert GmbH', $document->refresh()->supplier);
        $this->assertSame('Musterlieferant GmbH', $document->runs()->sole()->result['supplier']);
        $this->get(route('documents.download', $document))->assertOk()->assertDownload('document-'.$document->id.'.txt');
        $this->get('/admin/documents/'.$document->id)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($reviewer);
        Livewire::test(ViewDocument::class, ['record' => $document->id])->callAction('approve')->assertHasNoErrors();
        $this->assertSame(DocumentStatus::Approved, $document->refresh()->status);
        $this->get(route('documents.export', $document))->assertOk()->assertSee('Korrigiert GmbH');
        $this->assertSame(1, AuditEntry::where('action', 'corrected')->count());
        $this->assertSame(1, AuditEntry::where('action', 'approved')->count());
        $this->assertSame(1, AuditEntry::where('action', 'exported')->count());
    }

    public function test_ai_field_can_be_restored_as_new_revision_with_notification(): void
    {
        $editor = User::factory()->create();
        $document = $this->upload($editor);
        app(ProcessExtraction::class)->handle($document->runs()->sole()->id);
        $this->actingAs($editor);
        Livewire::test(EditDocument::class, ['record' => $document->id])->fillForm([...$this->fields(), 'supplier' => 'Manuell GmbH'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Manuell GmbH', $document->refresh()->supplier);
        Livewire::test(EditDocument::class, ['record' => $document->id])->call('resetAiField', 'supplier')->assertHasNoErrors()->assertNotified();
        $this->assertSame('Musterlieferant GmbH', $document->refresh()->supplier);
        $this->assertSame(3, $document->revision);
        $this->assertSame(1, AuditEntry::where('action', 'field_reset')->count());
    }

    public function test_approved_document_cannot_be_corrected_or_reapproved(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $document = $this->upload($admin);
        app(ProcessExtraction::class)->handle($document->runs()->sole()->id);
        app(ApproveDocument::class)->handle($admin, $document->refresh(), $document->revision);
        $this->actingAs($admin)->get('/admin/documents/'.$document->id.'/edit')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CorrectDocument::class)->handle($admin, $document->refresh(), $document->revision, $this->fields());
    }

    public function test_concurrent_edit_is_rejected(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        app(CorrectDocument::class)->handle($user, $document, 0, $this->fields());
        $this->expectException(ValidationException::class);
        app(CorrectDocument::class)->handle($user, $document, 0, [...$this->fields(), 'supplier' => 'stale']);
    }

    public function test_csv_neutralizes_formulas(): void
    {
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $document = $this->upload($user);
        $document = app(CorrectDocument::class)->handle($user, $document, 0, [...$this->fields(), 'supplier' => ' =HYPERLINK("bad")', 'invoice_number' => '@SUM(1)']);
        app(ApproveDocument::class)->handle($user, $document, $document->revision);
        $csv = app(ExportDocument::class)->handle($user, $document);
        $this->assertStringContainsString("' =HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
    }
}
