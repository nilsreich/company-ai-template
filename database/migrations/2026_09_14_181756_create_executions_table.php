<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained();
            $table->unsignedInteger('input_version');
            $table->unsignedInteger('task_revision');
            $table->string('status')->default('queued')->index();
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->string('fake_scenario')->default('success');
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('result')->nullable();
            $table->jsonb('confidence')->nullable();
            $table->jsonb('usage')->nullable();
            $table->boolean('applied')->default(false);
            $table->string('error_category')->nullable();
            $table->uuid('lease_owner')->nullable();
            $table->timestampTz('lease_until')->nullable()->index();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
