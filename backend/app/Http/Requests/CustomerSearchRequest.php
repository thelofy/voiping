<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Customer::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'phone' => trim((string) $this->input('phone', '')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }
}
