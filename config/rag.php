<?php

return [
    'enabled' => env('RAG_ENABLED', true),
    'chunk_size' => (int) env('RAG_CHUNK_SIZE', 2000),
    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    'max_chunks' => (int) env('RAG_MAX_CHUNKS', 50),
    'retrieval_limit' => (int) env('RAG_RETRIEVAL_LIMIT', 10),
];
