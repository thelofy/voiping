<?php

namespace App\Services;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\Customer;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records and lists call logs. This service does not talk to Asterisk.
 */
class CallLogService
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): Call
    {
        $phone = $this->phone($attributes['phone'] ?? null);
        $extension = $this->extension($attributes['extension'] ?? null);
        $direction = $this->direction($attributes['direction'] ?? null);
        $status = $this->status($attributes['status'] ?? null);
        $uniqueId = $this->uniqueId($attributes['unique_id'] ?? null);
        $customerId = $this->existingId($attributes['customer_id'] ?? null, Customer::class, 'customer_id');
        $userId = $this->existingId($attributes['user_id'] ?? null, User::class, 'user_id');

        $startedAt = $this->moment($attributes['started_at'] ?? null, 'started_at', required: true);
        $answeredAt = $this->moment($attributes['answered_at'] ?? null, 'answered_at', required: false);
        $endedAt = $this->moment($attributes['ended_at'] ?? null, 'ended_at', required: false);

        $this->assertTimeline($status, $startedAt, $answeredAt, $endedAt);

        $duration = $status === CallStatus::Ended
            ? $endedAt->getTimestamp() - $answeredAt->getTimestamp()
            : null;

        $linkedId = $uniqueId ?? (string) Str::uuid();

        if ($uniqueId !== null && Call::query()->where('unique_id', $uniqueId)->exists()) {
            throw ValidationException::withMessages([
                'unique_id' => 'This call identifier is already recorded.',
            ]);
        }

        try {
            $call = Call::query()->create([
                'customer_id' => $customerId,
                'user_id' => $userId,
                'phone' => $phone,
                'caller_number_raw' => $phone,
                'caller_number_normalized' => $this->phones->normalize($phone) ?? '',
                'extension' => $extension,
                'extension_number' => $extension,
                'asterisk_linkedid' => $linkedId,
                'asterisk_uniqueid' => $uniqueId ?? $linkedId,
                'duration_seconds' => $duration,
                'direction' => $direction,
                'status' => $status,
                'unique_id' => $uniqueId,
                'started_at' => $startedAt,
                'answered_at' => $answeredAt,
                'ended_at' => $endedAt,
                'duration' => $duration,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'unique_id' => 'This call identifier is already recorded.',
            ]);
        }

        return $call->load(['customer', 'user']);
    }

    /**
     * Administrators receive every matching row. Every other viewer is limited to their own logs.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Call>
     */
    public function paginate(array $filters, User $viewer): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min(100, $perPage));

        $query = Call::query()
            ->with(['customer', 'user'])
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if (! $viewer->isAdministrator()) {
            $query->where('user_id', $viewer->id);
        }

        if ($this->filled($filters['phone'] ?? null)) {
            $phone = addcslashes(trim((string) $filters['phone']), '%_\\');
            $query->where('phone', 'like', '%'.$phone.'%');
        }

        if ($this->filled($filters['extension'] ?? null)) {
            $query->where('extension', trim((string) $filters['extension']));
        }

        if ($this->filled($filters['direction'] ?? null)) {
            $query->where('direction', (string) $filters['direction']);
        }

        if ($this->filled($filters['status'] ?? null)) {
            $query->where('status', (string) $filters['status']);
        }

        if ($this->filled($filters['customer_id'] ?? null)) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if ($this->filled($filters['unique_id'] ?? null)) {
            $query->where('unique_id', trim((string) $filters['unique_id']));
        }

        if ($this->filled($filters['started_from'] ?? null)) {
            $query->where('started_at', '>=', $this->rangeStart((string) $filters['started_from']));
        }

        if ($this->filled($filters['started_to'] ?? null)) {
            $query->where('started_at', '<=', $this->rangeEnd((string) $filters['started_to']));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    private function phone(mixed $value): string
    {
        $phone = trim((string) $value);

        if ($phone === '' || strlen($phone) > 40 || preg_match('/^\+?[0-9][0-9\s().\-]*$/', $phone) !== 1) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a phone number up to 40 characters.',
            ]);
        }

        return $phone;
    }

    private function extension(mixed $value): ?string
    {
        if (! $this->filled($value)) {
            return null;
        }

        $extension = trim((string) $value);

        if (strlen($extension) > 20 || preg_match('/^[0-9]+$/', $extension) !== 1) {
            throw ValidationException::withMessages([
                'extension' => 'Extension must be digits, up to 20 characters.',
            ]);
        }

        return $extension;
    }

    private function direction(mixed $value): CallDirection
    {
        if ($value instanceof CallDirection) {
            return $value;
        }

        $direction = CallDirection::tryFrom((string) $value);

        if ($direction === null) {
            throw ValidationException::withMessages([
                'direction' => 'Direction must be inbound or outbound.',
            ]);
        }

        return $direction;
    }

    private function status(mixed $value): CallStatus
    {
        if ($value instanceof CallStatus) {
            return $value;
        }

        $status = CallStatus::tryFrom((string) $value);

        if ($status === null) {
            throw ValidationException::withMessages([
                'status' => 'Status is not recognized.',
            ]);
        }

        return $status;
    }

    private function uniqueId(mixed $value): ?string
    {
        if (! $this->filled($value)) {
            return null;
        }

        $uniqueId = trim((string) $value);

        if (strlen($uniqueId) > 64 || preg_match('/^[A-Za-z0-9._:\-]+$/', $uniqueId) !== 1) {
            throw ValidationException::withMessages([
                'unique_id' => 'The call identifier must be 1 to 64 letters, digits, or . _ : - characters.',
            ]);
        }

        return $uniqueId;
    }

    /**
     * @param  class-string<Customer|User>  $model
     */
    private function existingId(mixed $value, string $model, string $field): ?int
    {
        if (! $this->filled($value)) {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw ValidationException::withMessages([
                $field => 'The selected record does not exist.',
            ]);
        }

        $id = (int) $value;

        if (! $model::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                $field => 'The selected record does not exist.',
            ]);
        }

        return $id;
    }

    private function moment(mixed $value, string $field, bool $required): ?Carbon
    {
        if (! $this->filled($value)) {
            if ($required) {
                throw ValidationException::withMessages([
                    $field => 'This time is required.',
                ]);
            }

            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid time.',
            ]);
        }
    }

    private function assertTimeline(CallStatus $status, Carbon $startedAt, ?Carbon $answeredAt, ?Carbon $endedAt): void
    {
        if ($answeredAt !== null && $answeredAt->lt($startedAt)) {
            throw ValidationException::withMessages([
                'answered_at' => 'Answer time cannot be before the call started.',
            ]);
        }

        if ($endedAt !== null && $endedAt->lt($startedAt)) {
            throw ValidationException::withMessages([
                'ended_at' => 'End time cannot be before the call started.',
            ]);
        }

        if ($answeredAt !== null && $endedAt !== null && $endedAt->lt($answeredAt)) {
            throw ValidationException::withMessages([
                'ended_at' => 'End time cannot be before the call was answered.',
            ]);
        }

        $answered = $answeredAt !== null;
        $ended = $endedAt !== null;

        $valid = match ($status) {
            CallStatus::Ringing => ! $answered && ! $ended,
            CallStatus::Answered => $answered && ! $ended,
            CallStatus::Ended => $answered && $ended,
            CallStatus::Missed, CallStatus::Busy, CallStatus::Failed => ! $answered && $ended,
            CallStatus::Incomplete => ! $ended,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                'status' => 'The times do not match this status. '.$status->description(),
            ]);
        }
    }

    private function rangeStart(string $value): Carbon
    {
        $parsed = Carbon::parse($value);

        return strlen(trim($value)) <= 10 ? $parsed->startOfDay() : $parsed;
    }

    private function rangeEnd(string $value): Carbon
    {
        $parsed = Carbon::parse($value);

        return strlen(trim($value)) <= 10 ? $parsed->endOfDay() : $parsed;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
