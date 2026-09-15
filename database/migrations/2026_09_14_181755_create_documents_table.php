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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('original_name');
            $table->string('path');
            $table->string('sha256', 64);
            $table->unsignedInteger('input_version')->default(1);
            $table->unsignedInteger('revision')->default(0);
            $table->string('status')->default('draft')->index();
            $table->string('supplier')->nullable();
            $table->string('invoice_number', 120)->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('total_amount', 18, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
