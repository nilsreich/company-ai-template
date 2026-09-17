<?php

namespace App\Actions;

use App\Models\Task;
use App\Models\TaskChunk;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Keyword retrieval foundation with the permission check first:
 * only chunks of tasks the actor may view are searched.
 */
final class RetrieveChunks
{
    /** @return array<int, array{id: int, task_id: int, chunk_index: int, content: string}> */
    public function handle(User $actor, string $query, ?int $limit = null): array
    {
        Gate::forUser($actor)->authorize('viewAny', Task::class);
        $query = trim(mb_substr($query, 0, 500));
        if ($query === '') {
            return [];
        }
        $limit = max(1, min($limit ?? config()->integer('rag.retrieval_limit', 10), 50));

        $chunks = TaskChunk::query()->with('task')
            ->where('content', 'ilike', '%'.$query.'%')
            ->orderBy('task_id')->orderBy('chunk_index')->limit($limit * 3)->get();

        $result = [];
        foreach ($chunks as $chunk) {
            if ($chunk->task && Gate::forUser($actor)->allows('view', $chunk->task)) {
                $result[] = ['id' => $chunk->id, 'task_id' => $chunk->task_id, 'chunk_index' => $chunk->chunk_index, 'content' => $chunk->content];
            }
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
