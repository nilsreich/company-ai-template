<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateUserAccess
{
    public function handle(User $actor, User $subject, Role $role, bool $active): User
    {
        return DB::transaction(function () use ($actor, $subject, $role, $active): User {
            // Serialize administrator changes, including concurrent last-admin demotions.
            DB::select('SELECT pg_advisory_xact_lock(894301)');
            $actor->refresh();
            $subject->refresh();
            Gate::forUser($actor)->authorize('update', $subject);
            if ($subject->active && $subject->role === Role::Admin && (! $active || $role !== Role::Admin) && User::where('active', true)->where('role', Role::Admin)->count() <= 1) {
                throw ValidationException::withMessages(['data.role' => 'Der letzte aktive Administrator muss erhalten bleiben.']);
            }
            $subject->forceFill(['role' => $role, 'active' => $active])->save();
            Audit::record('user_access_changed', $actor, null, ['subject_id' => $subject->id, 'role' => $role->value, 'active' => $active]);

            return $subject;
        });
    }
}
