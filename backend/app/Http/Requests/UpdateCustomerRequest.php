<?php

namespace App\Http\Requests;

use App\Models\Customer;

class UpdateCustomerRequest extends CustomerFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->customer()) ?? false;
    }

    private function customer(): Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : new Customer;
    }
}
