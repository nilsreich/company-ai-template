<?php

namespace Tests\Feature;

use App\Actions\ApproveDocument;
use App\Actions\CorrectDocument;
use App\Actions\ExportDocument;
use App\Actions\ProcessExtraction;
use App\Actions\RejectAuditCleanup;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_fachablauf_erzeugt_lueckenlose_hashkette(): void
    {
        $editor = User::factory()->create();
        $reviewer = User::factory()->create(['role' => Role::Reviewer]);
        $document = $this->upload($editor);
        app(ProcessExtraction::class)->handle($document->runs()->sole()->id);
        $document = app(CorrectDocument::class)->handle($editor, $document->refresh(), $document->revision, $this->fields());
        app(ApproveDocument::class)->handle($reviewer, $document->refresh(), $document->revision);
        app(ExportDocument::class)->handle($reviewer, $document->refresh());

        $entries = AuditEntry::where('document_id', $document->id)->orderBy('chain_position')->get();
        $this->assertGreaterThanOrEqual(5, $entries->count());
        $positions = $entries->pluck('chain_position')->all();
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertSame($positions, array_values(array_unique($positions)));
        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry->entry_hash);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry->previous_hash);
        }
        foreach (['document_received', 'extraction_completed', 'corrected', 'approved', 'exported'] as $action) {
            $this->assertTrue($entries->contains('action', $action), 'Audit-Aktion fehlt: '.$action);
        }

        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_audit_eintraege_sind_unveraenderbar(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);
        $entry = AuditEntry::where('document_id', $document->id)->firstOrFail();

        $this->expectException(\Throwable::class);
        $entry->update(['description' => 'manipuliert']);
    }

    public function test_audit_cleanup_wird_abgelehnt(): void
    {
        $this->expectException(\LogicException::class);
        app(RejectAuditCleanup::class)->execute(30);
    }
}
