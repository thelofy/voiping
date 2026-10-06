<?php

namespace App\Policies;

use App\Models\Call;
use App\Models\User;

/**
 * Administrators see every call log. Operators see logs assigned to them.
 * Recording a log through the API is limited to administrators until ingest exists.
 */
class CallPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator() || $user->isOperator();
    }

    public function view(User $user, Call $call): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return $user->isOperator() && $call->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }
}
