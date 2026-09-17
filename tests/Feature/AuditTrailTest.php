<?php

namespace Tests\Feature;

use App\Actions\ApproveTask;
use App\Actions\CorrectTask;
use App\Actions\ExportTask;
use App\Actions\ProcessExecution;
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
        $task = $this->upload($editor);
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        $task = app(CorrectTask::class)->handle($editor, $task->refresh(), $task->revision, $this->taskPayload());
        app(ApproveTask::class)->handle($reviewer, $task->refresh(), $task->revision);
        app(ExportTask::class)->handle($reviewer, $task->refresh());

        $entries = AuditEntry::where('task_id', $task->id)->orderBy('chain_position')->get();
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
        foreach (['task_received', 'execution_completed', 'corrected', 'approved', 'exported'] as $action) {
            $this->assertTrue($entries->contains('action', $action), 'Audit-Aktion fehlt: '.$action);
        }

        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_audit_eintraege_sind_unveraenderbar(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $entry = AuditEntry::where('task_id', $task->id)->firstOrFail();

        $this->expectException(\Throwable::class);
        $entry->update(['description' => 'manipuliert']);
    }

    public function test_audit_cleanup_wird_abgelehnt(): void
    {
        $this->expectException(\LogicException::class);
        app(RejectAuditCleanup::class)->execute(30);
    }
}
