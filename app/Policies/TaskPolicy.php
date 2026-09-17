<?php

namespace App\Policies;

use App\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
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

    public function view(User $user, Task $task): bool
    {
        return $user->active;
    }

    public function create(User $user): bool
    {
        return $user->active;
    }

    public function update(User $user, Task $task): bool
    {
        return $user->active && $task->status !== TaskStatus::Approved;
    }

    public function download(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function approve(User $user, Task $task): bool
    {
        return $user->active && $user->role !== Role::Editor && $task->status === TaskStatus::InReview;
    }

    public function export(User $user, Task $task): bool
    {
        return $user->active && $user->role !== Role::Editor && $task->status === TaskStatus::Approved;
    }

    public function submitGolden(User $user, Task $task): bool
    {
        return $user->active && $user->role === Role::Admin && $task->status === TaskStatus::Approved && $task->mime_type === 'application/pdf';
    }

    public function retry(User $user, Task $task): bool
    {
        return $this->update($user, $task) && $task->executions()->latest('id')->first()?->status === ExecutionStatus::Failed;
    }
}
