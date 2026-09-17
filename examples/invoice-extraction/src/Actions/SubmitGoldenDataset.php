<?php

namespace Examples\InvoiceExtraction\Actions;

use App\Actions\Audit;
use App\Models\Task;
use App\Models\User;
use Examples\InvoiceExtraction\Support\InvoiceFieldAssessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SubmitGoldenDataset
{
    public function handle(User $actor, Task $task): string
    {
        return DB::transaction(function () use ($actor, $task): string {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('submitGolden', $task);
            app(InvoiceFieldAssessment::class)->assertApprovable($task->payload());
            $original = Storage::disk('private')->get($task->path);
            abort_unless(is_string($original) && hash_equals($task->sha256, hash('sha256', $original)), 409, 'Originaldatei stimmt nicht mit der gespeicherten Prüfsumme überein.');
            $root = config()->string('golden.path');
            File::ensureDirectoryExists($root, 0700);
            $name = 'task-'.$task->id.'-r'.$task->revision;
            $destination = $root.'/'.$name;
            if (is_dir($destination)) {
                $manifest = json_decode(File::get($destination.'/expected.json'), true, flags: JSON_THROW_ON_ERROR);
                abort_unless(hash_file('sha256', $destination.'/original.pdf') === $task->sha256 && ($manifest['fields'] ?? []) === $task->payload(), 409, 'Vorhandenes Fixture ist nicht identisch.');
                Audit::record('golden_dataset_reused', $actor, $task, ['fixture' => $name, 'revision' => $task->revision]);

                return $name;
            }
            $temporary = $root.'/.pending-'.Str::uuid();
            File::makeDirectory($temporary, 0700);
            try {
                File::put($temporary.'/original.pdf', $original);
                $run = $task->originalExecution();
                $manifest = [
                    'schema_version' => 1, 'task_id' => $task->id, 'revision' => $task->revision,
                    'source_sha256' => $task->sha256, 'mime_type' => 'application/pdf',
                    'approved_by' => $task->approved_by, 'approved_at' => $task->approved_at?->toIso8601String(),
                    'submitted_by' => $actor->id, 'submitted_at' => now()->toIso8601String(),
                    'fields' => $task->payload(),
                    'source_run' => $run ? ['id' => $run->id, 'model' => $run->model, 'prompt_version' => $run->prompt_version] : null,
                ];
                File::put($temporary.'/expected.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                chmod($temporary.'/original.pdf', 0600);
                chmod($temporary.'/expected.json', 0600);
                Audit::record('golden_dataset_submitted', $actor, $task, ['fixture' => $name, 'revision' => $task->revision, 'source_sha256' => $task->sha256, 'manifest_sha256' => hash_file('sha256', $temporary.'/expected.json')]);
                if (! rename($temporary, $destination)) {
                    throw new \RuntimeException('Fixture konnte nicht veröffentlicht werden.');
                }

                return $name;
            } catch (\Throwable $exception) {
                File::deleteDirectory($temporary);
                throw $exception;
            }
        });
    }
}
