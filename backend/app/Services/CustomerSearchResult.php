<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CustomerSearchResult
{
    /**
     * @param  LengthAwarePaginator<int, Customer>  $customers
     */
    public function __construct(
        public LengthAwarePaginator $customers,
        public bool $phoneUnsearchable,
    ) {}
}
