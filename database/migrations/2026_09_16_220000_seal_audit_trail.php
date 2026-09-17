<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->string('log_name')->default('tasks')->index();
            $table->text('description')->nullable();
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->string('event')->nullable();
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->bigInteger('chain_position')->nullable()->unique();
            $table->string('previous_hash', 64)->nullable();
            $table->string('entry_hash', 64)->nullable();
        });
        DB::statement('LOCK TABLE audit_entries IN ACCESS EXCLUSIVE MODE');
        DB::statement("UPDATE audit_entries SET description = action, event = action, properties = changes || '{\"legacy_import\":true}'::jsonb, subject_id = task_id, subject_type = CASE WHEN task_id IS NOT NULL THEN 'App\\Models\\Task' END, causer_id = user_id, causer_type = CASE WHEN user_id IS NOT NULL THEN 'App\\Models\\User' END, updated_at = created_at");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_entry_digest(item audit_entries) RETURNS text
LANGUAGE sql IMMUTABLE SET timezone = 'UTC' AS $$
    SELECT encode(sha256(convert_to('audit-v1|' || item.previous_hash || '|' || (to_jsonb(item) - 'entry_hash')::text, 'UTF8')), 'hex');
$$;
DO $$
DECLARE item audit_entries%ROWTYPE; previous text := repeat('0',64); position bigint := 0;
BEGIN
    FOR item IN SELECT * FROM audit_entries ORDER BY id LOOP
        position := position + 1;
        item.chain_position := position;
        item.previous_hash := previous;
        item.entry_hash := audit_entry_digest(item);
        UPDATE audit_entries SET chain_position = position, previous_hash = previous, entry_hash = item.entry_hash WHERE id = item.id;
        previous := item.entry_hash;
    END LOOP;
END $$;
CREATE OR REPLACE FUNCTION seal_audit_entry() RETURNS trigger LANGUAGE plpgsql SET timezone = 'UTC' AS $$
DECLARE tail audit_entries%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(73842901);
    SELECT * INTO tail FROM audit_entries ORDER BY chain_position DESC LIMIT 1;
    NEW.chain_position := COALESCE(tail.chain_position, 0) + 1;
    NEW.previous_hash := COALESCE(tail.entry_hash, repeat('0',64));
    NEW.entry_hash := audit_entry_digest(NEW);
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Audit trail is append-only' USING ERRCODE = '42501'; END $$;
CREATE TRIGGER audit_insert_seal BEFORE INSERT ON audit_entries FOR EACH ROW EXECUTE FUNCTION seal_audit_entry();
CREATE TRIGGER audit_no_mutation BEFORE UPDATE OR DELETE ON audit_entries FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();
CREATE TRIGGER audit_no_truncate BEFORE TRUNCATE ON audit_entries FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation();
ALTER TABLE audit_entries ALTER COLUMN chain_position SET NOT NULL;
ALTER TABLE audit_entries ALTER COLUMN previous_hash SET NOT NULL;
ALTER TABLE audit_entries ALTER COLUMN entry_hash SET NOT NULL;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Audit-Versiegelung wird nicht zurückgerollt. Eine neue vorwärtsgerichtete Migration ist erforderlich.');
    }
};
