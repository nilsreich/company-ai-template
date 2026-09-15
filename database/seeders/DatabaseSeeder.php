<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo-Seeds sind nur local/testing erlaubt.');
        }
        foreach (Role::cases() as $role) {
            $user = User::firstOrNew(['email' => $role->value.'@example.test', 'is_demo' => true]);
            $user->forceFill(['name' => ucfirst($role->value), 'role' => $role, 'active' => true, 'is_demo' => true])->save();
        }
    }
}
