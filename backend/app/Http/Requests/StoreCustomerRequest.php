<?php

namespace App\Http\Requests;

use App\Models\Customer;

class StoreCustomerRequest extends CustomerFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }
}
