<?php

namespace App\Http\Controllers;

use App\Actions\ExportTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public function download(Task $task): StreamedResponse
    {
        Gate::authorize('download', $task);

        return Storage::disk('private')->download($task->path, 'task-'.$task->id.($task->mime_type === 'application/pdf' ? '.pdf' : '.txt'), ['Content-Type' => $task->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(Task $task): StreamedResponse
    {
        Gate::authorize('view', $task);

        return Storage::disk('private')->response($task->path, 'task-'.$task->id.($task->mime_type === 'application/pdf' ? '.pdf' : '.txt'), [
            'Content-Type' => $task->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }

    public function export(Request $request, Task $task, ExportTask $export): Response
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return response($export->handle($actor, $task))->header('Content-Type', 'application/json; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="task-'.$task->id.'.json"')->header('Cache-Control', 'private, no-store');
    }

    public function status(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);
        $execution = $task->executions()->latest('id')->first();

        return response()->json(['status' => $task->status->value, 'execution_status' => $execution?->status->value])->header('Cache-Control', 'private, no-store');
    }
}
