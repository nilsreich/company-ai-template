<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('title', 160);
            $table->text('description');
            $table->jsonb('metadata');
            $table->string('screenshot_path')->nullable();
            $table->string('repository');
            $table->string('status', 20)->default('sending');
            $table->unsignedBigInteger('issue_number')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
