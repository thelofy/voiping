<?php

namespace App\Http\Requests;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCallLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('viewAny', Call::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:40'],
            'extension' => ['nullable', 'string', 'max:20'],
            'direction' => ['nullable', Rule::enum(CallDirection::class)],
            'status' => ['nullable', Rule::enum(CallStatus::class)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'unique_id' => ['nullable', 'string', 'max:64'],
            'started_from' => ['nullable', 'date'],
            'started_to' => [
                'nullable',
                'date',
                Rule::when($this->filled('started_from'), ['after_or_equal:started_from']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
