<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPhone;

class CustomerMatcher
{
    /**
     * Match a caller by the existing normalized phone key.
     * Directory status does not exclude a customer.
     */
    public function findByNormalizedNumber(?string $normalized): ?Customer
    {
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return CustomerPhone::query()
            ->where('normalized_number', $normalized)
            ->first()
            ?->customer;
    }
}
