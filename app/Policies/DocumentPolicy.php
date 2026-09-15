<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\Document;
use App\Models\User;

class DocumentPolicy
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

    public function view(User $user, Document $document): bool
    {
        return $user->active;
    }

    public function create(User $user): bool
    {
        return $user->active;
    }

    public function update(User $user, Document $document): bool
    {
        return $user->active && $document->status !== DocumentStatus::Approved;
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function approve(User $user, Document $document): bool
    {
        return $user->active && $user->role !== Role::Editor && $document->status === DocumentStatus::InReview;
    }

    public function export(User $user, Document $document): bool
    {
        return $user->active && $user->role !== Role::Editor && $document->status === DocumentStatus::Approved;
    }

    public function retry(User $user, Document $document): bool
    {
        return $this->update($user, $document) && $document->runs()->latest('id')->first()?->status === RunStatus::Failed;
    }
}
