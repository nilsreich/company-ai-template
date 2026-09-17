<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            // Reserved for a future embedding step (pgvector or external store).
            $table->jsonb('embedding')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->timestampsTz();
            $table->unique(['task_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_chunks');
    }
};
