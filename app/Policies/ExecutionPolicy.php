<?php

namespace App\Policies;

use App\Models\Execution;
use App\Models\User;

class ExecutionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        $user->refresh();

        return $user->active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->active;
    }

    public function view(User $user, Execution $execution): bool
    {
        return $user->active;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Execution $execution): bool
    {
        return false;
    }

    public function delete(User $user, Execution $execution): bool
    {
        return false;
    }
}
