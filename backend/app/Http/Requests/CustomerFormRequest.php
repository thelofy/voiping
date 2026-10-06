<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class CustomerFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $notes = $this->input('notes');
        $phones = collect($this->input('phones', []))
            ->filter(function (mixed $phone): bool {
                if (! is_array($phone)) {
                    return false;
                }

                return trim((string) ($phone['raw_number'] ?? '')) !== ''
                    || trim((string) ($phone['label'] ?? '')) !== '';
            })
            ->map(function (array $phone): array {
                return [
                    'raw_number' => trim((string) ($phone['raw_number'] ?? '')),
                    'label' => trim((string) ($phone['label'] ?? '')),
                ];
            })
            ->values()
            ->all();

        $company = $this->input('company_name');
        $email = $this->input('email');

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'company_name' => is_string($company) && trim($company) !== '' ? trim($company) : null,
            'email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
            'notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            'phones' => $phones,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'phones' => ['required', 'array', 'min:1', 'max:5'],
            'phones.*.raw_number' => ['required', 'string', 'max:40'],
            'phones.*.label' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phones.required' => 'Add at least one phone number.',
            'phones.min' => 'Add at least one phone number.',
            'phones.max' => 'A customer can have at most 5 phone numbers.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $customer = $this->route('customer');
            $errors = app(CustomerService::class)->phoneErrors(
                $this->input('phones', []),
                $customer instanceof Customer ? $customer : null,
            );

            foreach ($errors as $key => $messages) {
                foreach ((array) $messages as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        });
    }
}
