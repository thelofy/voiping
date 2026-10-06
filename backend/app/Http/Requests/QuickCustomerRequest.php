<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class QuickCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $company = $this->input('company_name');
        $notes = $this->input('notes');

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'company_name' => is_string($company) && trim($company) !== '' ? trim($company) : null,
            'notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            'phone' => trim((string) $this->input('phone', '')),
            'linkedid' => trim((string) $this->input('linkedid', '')),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
            'phone' => ['required', 'string', 'max:40'],
            'linkedid' => ['required', 'string', 'max:64'],
        ];
    }
}
