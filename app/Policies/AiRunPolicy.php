<?php

namespace App\Policies;

use App\Models\AiRun;
use App\Models\User;

class AiRunPolicy
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

    public function view(User $user, AiRun $run): bool
    {
        return $user->active;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiRun $run): bool
    {
        return false;
    }

    public function delete(User $user, AiRun $run): bool
    {
        return false;
    }
}
