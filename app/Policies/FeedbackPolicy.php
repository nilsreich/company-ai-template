<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Feedback;
use App\Models\User;

class FeedbackPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        $user->refresh();

        return $user->active ? null : false;
    }

    public function create(User $user): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function view(User $user, Feedback $feedback): bool
    {
        return $user->role === Role::Admin || $feedback->user_id === $user->id;
    }

    public function screenshot(User $user, Feedback $feedback): bool
    {
        return $user->role === Role::Admin;
    }
}
