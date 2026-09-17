@if($task)
    @if($task->mime_type === 'application/pdf')
        <iframe src="{{ route('tasks.preview', $task) }}" title="Original-PDF" loading="lazy" class="h-[70vh] w-full border-0"></iframe>
        <a href="{{ route('tasks.download', $task) }}" class="mt-2 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Original-PDF herunterladen</a>
    @elseif(\Illuminate\Support\Facades\Storage::disk('private')->exists($task->path))
        <pre class="max-h-[70vh] overflow-y-auto whitespace-pre-wrap break-words">{{ \Illuminate\Support\Facades\Storage::disk('private')->get($task->path) }}</pre>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">Die Originaldatei steht nicht mehr zur Verfügung.</p>
    @endif
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">Keine Aufgabe ausgewählt.</p>
@endif
