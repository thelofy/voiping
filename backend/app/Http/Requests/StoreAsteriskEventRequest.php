<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StoreAsteriskEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            $this->failJson(['body' => ['The Content-Type must be application/json.']]);
        }

        json_decode($this->getContent() === '' ? '' : $this->getContent());

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->failJson(['body' => ['The payload must be valid JSON.']]);
        }

        $asterisk = $this->input('asterisk');
        $call = $this->input('call');

        if (is_array($asterisk)) {
            foreach (['uniqueid', 'linkedid', 'channel'] as $key) {
                if (isset($asterisk[$key]) && is_string($asterisk[$key])) {
                    $asterisk[$key] = trim($asterisk[$key]);
                }
            }

            $this->merge(['asterisk' => $asterisk]);
        }

        if (is_array($call)) {
            foreach (['caller_number', 'caller_name', 'extension', 'dial_status', 'direction'] as $key) {
                if (isset($call[$key]) && is_string($call[$key])) {
                    $call[$key] = trim($call[$key]);
                }
            }

            $this->merge(['call' => $call]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'in:call.ringing,call.answered,call.ended'],
            'occurred_at' => ['required', 'date'],
            'asterisk' => ['required', 'array'],
            'asterisk.uniqueid' => ['required', 'string', 'max:64'],
            'asterisk.linkedid' => ['required', 'string', 'max:64'],
            'asterisk.channel' => ['required', 'string', 'max:120'],
            'call' => ['required', 'array'],
            'call.caller_number' => [
                'present',
                'string',
                'max:40',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->input('event_type') !== 'call.ended' && $value === '') {
                        $fail('The caller number is required unless the event is call.ended.');
                    }
                },
            ],
            'call.caller_name' => ['nullable', 'string', 'max:120'],
            'call.extension' => ['required', 'string', 'max:20'],
            'call.dial_status' => ['nullable', 'string', 'max:32'],
            'call.direction' => ['nullable', 'in:inbound,outbound'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->logValidationFailure(array_keys($validator->failed()));

        parent::failedValidation($validator);
    }

    /**
     * @param  array<string, list<string>>  $messages
     */
    private function failJson(array $messages): void
    {
        $this->logValidationFailure(array_keys($messages));

        throw ValidationException::withMessages($messages);
    }

    /**
     * @param  list<string|int>  $fields
     */
    private function logValidationFailure(array $fields): void
    {
        $eventId = $this->input('event_id');

        Log::warning('asterisk.ingest.validation_failed', [
            'event_id' => is_string($eventId) && preg_match('/^[0-9a-fA-F-]{36}$/', $eventId) === 1 ? $eventId : null,
            'fields' => array_values(array_map('strval', $fields)),
        ]);
    }
}
