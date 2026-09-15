<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        $user->refresh();

        return $user->active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->active && $user->role === Role::Admin;
    }

    public function view(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, User $subject): bool
    {
        return false;
    }
}
