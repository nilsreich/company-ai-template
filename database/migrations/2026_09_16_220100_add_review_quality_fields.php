<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('mime_type')->default('text/plain');
            $table->decimal('net_amount', 18, 4)->nullable();
            $table->decimal('tax_amount', 18, 4)->nullable();
            $table->string('iban', 34)->nullable();
        });
        Schema::table('ai_runs', fn (Blueprint $table) => $table->jsonb('confidence')->nullable());
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_ai_result() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status = 'succeeded' AND (NEW.result IS DISTINCT FROM OLD.result OR NEW.confidence IS DISTINCT FROM OLD.confidence OR NEW.applied IS DISTINCT FROM OLD.applied) THEN
        RAISE EXCEPTION 'Completed AI results are immutable' USING ERRCODE = '42501';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER ai_result_immutable BEFORE UPDATE ON ai_runs FOR EACH ROW EXECUTE FUNCTION protect_ai_result();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER ai_result_immutable ON ai_runs; DROP FUNCTION protect_ai_result();');
        Schema::table('ai_runs', fn (Blueprint $table) => $table->dropColumn('confidence'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['mime_type', 'net_amount', 'tax_amount', 'iban']));
    }
};
