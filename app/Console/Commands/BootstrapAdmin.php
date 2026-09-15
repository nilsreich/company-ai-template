<?php

namespace App\Console\Commands;

use App\Actions\Audit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapAdmin extends Command
{
    protected $signature = 'app:bootstrap-admin {object-id : Verified Entra object UUID} {--name=Administrator}';

    protected $description = 'Activate the first administrator using the configured Entra tenant';

    public function handle(): int
    {
        $tenant = config('services.microsoft.tenant');
        $object = $this->argument('object-id');
        if (! is_string($tenant) || ! Str::isUuid($tenant) || ! Str::isUuid($object)) {
            $this->error('Tenant und Object-ID müssen gültige UUIDs sein.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($tenant, $object): int {
            DB::select('SELECT pg_advisory_xact_lock(894301)');
            if (User::where('role', Role::Admin)->where('active', true)->exists()) {
                $this->error('Ein aktiver Administrator existiert bereits. Benutzerverwaltung verwenden.');

                return self::FAILURE;
            }
            $user = User::where('entra_tenant_id', $tenant)->where('entra_object_id', $object)->first() ?? new User;
            $user->forceFill(['entra_tenant_id' => $tenant, 'entra_object_id' => $object]);
            $user->forceFill(['name' => (string) $this->option('name'), 'active' => true, 'role' => Role::Admin])->save();
            Audit::record('admin_bootstrapped_via_cli', null, null, ['subject_id' => $user->id]);
            $this->info('Administrator aktiviert.');

            return self::SUCCESS;
        });
    }
}
