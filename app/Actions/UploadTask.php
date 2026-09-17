<?php

namespace App\Actions;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class UploadTask
{
    public function __construct(private StartExecution $start) {}

    public function handle(User $actor, UploadedFile $file, ?string $title = null): Task
    {
        Gate::forUser($actor)->authorize('create', Task::class);
        $text = $file->getContent();
        $extension = strtolower($file->getClientOriginalExtension());
        $pdf = $extension === 'pdf';
        $validPdf = str_starts_with($text, '%PDF-') && str_contains(substr($text, -2048), '%%EOF') && $file->getMimeType() === 'application/pdf';
        $validText = $extension === 'txt' && trim($text) !== '' && mb_check_encoding($text, 'UTF-8') && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text);
        $limit = config()->integer($pdf ? 'tasks.pdf_max_kib' : 'tasks.max_kib') * 1024;
        if (! $file->isValid() || strlen($text) > $limit || ! ($pdf ? $validPdf : $validText)) {
            throw ValidationException::withMessages(['file' => 'Bitte eine gültige PDF- oder UTF-8-TXT-Datei innerhalb der Größenbegrenzung hochladen.']);
        }
        $path = $file->store('tasks', 'private');
        if (! is_string($path)) {
            throw new \RuntimeException('Private Datei konnte nicht gespeichert werden.');
        }
        try {
            return DB::transaction(function () use ($actor, $file, $path, $text, $pdf, $title): Task {
                $name = mb_substr(basename($file->getClientOriginalName()), 0, 255);
                $task = Task::create(['uploaded_by' => $actor->id, 'title' => mb_substr($title ?? pathinfo($name, PATHINFO_FILENAME), 0, 255), 'original_name' => $name, 'path' => $path, 'mime_type' => $pdf ? 'application/pdf' : 'text/plain', 'sha256' => hash('sha256', $text)]);
                $task->refresh();
                Audit::record('task_received', $actor, $task, ['sha256' => $task->sha256, 'mime_type' => $task->mime_type, 'size' => strlen($text), 'revision' => 0]);
                if (! $pdf) {
                    app(ChunkTask::class)->handle($task, $text);
                }
                $this->start->handle($actor, $task, false);

                return $task;
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }
    }
}
