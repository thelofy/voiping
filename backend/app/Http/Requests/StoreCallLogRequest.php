<?php

namespace App\Http\Requests;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCallLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create', Call::class);
    }

    protected function prepareForValidation(): void
    {
        $optional = ['customer_id', 'user_id', 'extension', 'unique_id', 'answered_at', 'ended_at'];

        $merged = [];

        foreach ($optional as $field) {
            if ($this->exists($field) && ! $this->filled($field)) {
                $merged[$field] = null;
            }
        }

        if ($this->exists('phone')) {
            $merged['phone'] = trim((string) $this->input('phone'));
        }

        if ($this->filled('extension')) {
            $merged['extension'] = trim((string) $this->input('extension'));
        }

        if ($this->filled('unique_id')) {
            $merged['unique_id'] = trim((string) $this->input('unique_id'));
        }

        $this->merge($merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().\-]*$/'],
            'extension' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'direction' => ['required', Rule::enum(CallDirection::class)],
            'status' => ['required', Rule::enum(CallStatus::class)],
            'unique_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/', Rule::unique('calls', 'unique_id')],
            'started_at' => ['required', 'date'],
            'answered_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
        ];
    }
}
