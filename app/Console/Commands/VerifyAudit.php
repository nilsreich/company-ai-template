<?php

namespace App\Console\Commands;

use App\Models\AuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyAudit extends Command
{
    protected $signature = 'audit:verify {--anchor= : Previously exported JSON anchor} {--write-anchor= : Write a new anchor to a new file}';

    protected $description = 'Verify the audit hash chain and optionally check or export an external anchor';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            DB::select('SELECT pg_advisory_xact_lock(73842901)');
            $previous = str_repeat('0', 64);
            $position = 0;
            foreach (AuditEntry::query()->selectRaw('audit_entries.*, audit_entry_digest(audit_entries) AS calculated_hash')->orderBy('chain_position')->cursor() as $entry) {
                $position++;
                $calculated = is_string($entry->calculated_hash) ? $entry->calculated_hash : '';
                if ($entry->chain_position !== $position || $entry->previous_hash !== $previous || ! is_string($entry->entry_hash) || ! hash_equals($entry->entry_hash, $calculated)) {
                    $this->error('Integritätsfehler an Kettenposition '.$position);

                    return self::FAILURE;
                }
                $previous = $entry->entry_hash;
            }
            if ($path = $this->option('anchor')) {
                try {
                    $anchor = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                    $entry = AuditEntry::where('chain_position', $anchor['position'] ?? -1)->first();
                    if (! $entry || $entry->entry_hash !== ($anchor['hash'] ?? null)) {
                        throw new \RuntimeException;
                    }
                } catch (\Throwable) {
                    $this->error('Externer Anker fehlt oder stimmt nicht überein.');

                    return self::FAILURE;
                }
            }
            if ($path = $this->option('write-anchor')) {
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    $this->error('Ankerdatei muss neu und schreibbar sein.');

                    return self::FAILURE;
                }
                chmod($path, 0600);
                fwrite($handle, json_encode(['version' => 1, 'position' => $position, 'hash' => $previous, 'created_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)."\n");
                fclose($handle);
            }
            $this->info('Audit-Kette gültig: '.$position.' Einträge. Letzter Hash: '.$previous);

            return self::SUCCESS;
        });
    }
}
