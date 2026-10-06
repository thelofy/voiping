<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator() || $user->isOperator();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->isAdministrator() || $user->isOperator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator() || $user->isOperator();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->isAdministrator() || $user->isOperator();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdministrator();
    }
}
