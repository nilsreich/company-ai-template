<?php

namespace Tests\Feature;

use App\Actions\ApproveTask;
use App\Actions\CorrectTask;
use App\Actions\ExportTask;
use App\Actions\ProcessExecution;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Jobs\RunExecution;
use App\Models\AuditEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaskFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_corrections_are_reported_on_the_form_without_writing(): void
    {
        $editor = User::factory()->create();
        $task = $this->upload($editor);
        $this->actingAs($editor);
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['title' => 'Korrigiert', 'payload_json' => 'kein json'])
            ->call('save')->assertHasFormErrors(['payload_json']);
        $this->assertSame(0, $task->refresh()->revision);
        $this->assertSame([], $task->payload());
    }

    public function test_invalid_utf8_is_reported_on_the_upload_field(): void
    {
        Storage::fake('private');
        Queue::fake();
        $this->actingAs(User::factory()->create());
        Livewire::test(CreateTask::class)
            ->fillForm(['file' => UploadedFile::fake()->createWithContent('invalid.txt', "Invalid \xFF UTF-8")])
            ->call('create')->assertHasFormErrors(['file']);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_complete_filament_flow_with_private_download_and_export(): void
    {
        Storage::fake('private');
        Queue::fake();
        $editor = User::factory()->create();
        $reviewer = User::factory()->create(['role' => Role::Reviewer]);
        $this->actingAs($editor);
        Livewire::test(CreateTask::class)->fillForm(['file' => UploadedFile::fake()->createWithContent('task.txt', 'Aufgabe <script>alert(1)</script>')])->call('create')->assertHasNoFormErrors();
        $task = Task::sole();
        Queue::assertPushed(RunExecution::class, fn ($job) => $job->executionId === $task->executions()->sole()->id);
        Storage::disk('private')->assertExists($task->path);
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        $this->assertSame(TaskStatus::InReview, $task->refresh()->status);
        Livewire::test(EditTask::class, ['record' => $task->id])->fillForm(['title' => 'Korrigiert', 'payload_json' => json_encode($this->taskPayload())])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Korrigierte Zusammenfassung', $task->refresh()->payload()['summary']);
        $this->assertSame('Demo-Zusammenfassung', $task->executions()->sole()->result['summary']);
        $this->get(route('tasks.download', $task))->assertOk()->assertDownload('task-'.$task->id.'.txt');
        $this->get('/admin/tasks/'.$task->id)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($reviewer);
        Livewire::test(ViewTask::class, ['record' => $task->id])->callAction('approve')->assertHasNoErrors();
        $this->assertSame(TaskStatus::Approved, $task->refresh()->status);
        $this->get(route('tasks.export', $task))->assertOk()->assertSee('Korrigierte Zusammenfassung');
        $this->assertSame(1, AuditEntry::where('action', 'corrected')->count());
        $this->assertSame(1, AuditEntry::where('action', 'approved')->count());
        $this->assertSame(1, AuditEntry::where('action', 'exported')->count());
    }

    public function test_ai_field_can_be_restored_as_new_revision_with_notification(): void
    {
        $editor = User::factory()->create();
        $task = $this->upload($editor);
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        $this->actingAs($editor);
        Livewire::test(EditTask::class, ['record' => $task->id])->fillForm(['title' => 'Korrigiert', 'payload_json' => json_encode([...$this->taskPayload(), 'summary' => 'Manuell'])])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Manuell', $task->refresh()->payload()['summary']);
        Livewire::test(EditTask::class, ['record' => $task->id])->call('resetTaskField', 'summary')->assertHasNoErrors()->assertNotified();
        $this->assertSame('Demo-Zusammenfassung', $task->refresh()->payload()['summary']);
        $this->assertSame(3, $task->revision);
        $this->assertSame(1, AuditEntry::where('action', 'field_reset')->count());
    }

    public function test_approved_task_cannot_be_corrected_or_reapproved(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $task = $this->upload($admin);
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        app(ApproveTask::class)->handle($admin, $task->refresh(), $task->revision);
        $this->actingAs($admin)->get('/admin/tasks/'.$task->id.'/edit')->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(CorrectTask::class)->handle($admin, $task->refresh(), $task->revision, $this->taskPayload());
    }

    public function test_concurrent_edit_is_rejected(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload(), 'Aktueller Titel');

        try {
            app(CorrectTask::class)->handle($user, $task, 0, [...$this->taskPayload(), 'summary' => 'stale'], 'Veralteter Titel');
            $this->fail('Veraltete Revision wurde akzeptiert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }

        $this->assertSame('Aktueller Titel', $task->refresh()->title);
        $expected = $this->taskPayload();
        $actual = $task->payload();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(1, $task->revision);
        $this->assertSame(1, AuditEntry::where('action', 'corrected')->count());
    }

    public function test_correction_updates_title_inside_the_locked_revision(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $updated = app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload(), '  Neuer Titel  ');
        $this->assertSame('Neuer Titel', $updated->title);
        $this->assertSame(1, $updated->revision);
        $this->assertSame('Neuer Titel', $task->refresh()->title);
        $entry = AuditEntry::where('action', 'corrected')->sole();
        $this->assertSame('Neuer Titel', $entry->changes['title_changed']);
    }

    public function test_unchanged_title_is_not_recorded_as_changed(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        $original = $task->title;
        app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload(), $original);
        $this->assertSame($original, $task->refresh()->title);
        $entry = AuditEntry::where('action', 'corrected')->sole();
        $this->assertNull($entry->changes['title_changed']);
    }

    public function test_blank_or_overlong_title_is_rejected_without_writing(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        try {
            app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload(), '   ');
            $this->fail('Leerer Titel wurde akzeptiert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }
        try {
            app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload(), str_repeat('ü', 256));
            $this->fail('Zu langer Titel wurde akzeptiert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }
        $this->assertSame(0, $task->refresh()->revision);
        $this->assertSame([], $task->payload());
    }

    public function test_edit_page_saves_title_through_the_action(): void
    {
        $user = User::factory()->create();
        $task = $this->upload($user);
        app(ProcessExecution::class)->handle($task->executions()->sole()->id);
        $this->actingAs($user);
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->fillForm(['title' => 'Vom Formular', 'payload_json' => json_encode($this->taskPayload())])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Vom Formular', $task->refresh()->title);
        $this->assertSame('Korrigierte Zusammenfassung', $task->payload()['summary']);
    }

    public function test_export_contains_task_payload_as_json(): void
    {
        $user = User::factory()->create(['role' => Role::Reviewer]);
        $task = $this->upload($user);
        $task = app(CorrectTask::class)->handle($user, $task, 0, $this->taskPayload());
        app(ApproveTask::class)->handle($user, $task, $task->revision);
        $json = app(ExportTask::class)->handle($user, $task);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($task->id, $data['task_id']);
        $this->assertSame('Korrigierte Zusammenfassung', $data['payload']['summary']);
    }
}
