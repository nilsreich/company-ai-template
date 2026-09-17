<?php

namespace App\Actions;

use App\Models\Task;

/**
 * Splits plain-text originals into overlapping retrieval chunks.
 *
 * PDFs are skipped until a versioned text/OCR step exists; embeddings
 * stay nullable until a concrete embedding provider is chosen.
 */
final class ChunkTask
{
    public function handle(Task $task, string $text): int
    {
        if ($task->mime_type !== 'text/plain') {
            return 0;
        }
        $size = max(100, config()->integer('rag.chunk_size', 2000));
        $overlap = min(max(0, config()->integer('rag.chunk_overlap', 200)), $size - 1);
        $max = max(1, config()->integer('rag.max_chunks', 50));

        $length = mb_strlen($text);
        $offset = 0;
        $index = 0;
        while ($offset < $length && $index < $max) {
            $piece = mb_substr($text, $offset, $size);
            $task->chunks()->create([
                'chunk_index' => $index, 'content' => $piece,
                'token_count' => (int) ceil(mb_strlen($piece) / 4),
            ]);
            $index++;
            $offset += $size - $overlap;
        }

        return $index;
    }
}
