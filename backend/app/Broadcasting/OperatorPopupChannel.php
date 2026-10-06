<?php

namespace App\Broadcasting;

use App\Models\User;

class OperatorPopupChannel
{
    public function join(User $user, int|string $userId): bool
    {
        return $user->is_active
            && $user->isOperator()
            && (int) $user->id === (int) $userId;
    }
}
