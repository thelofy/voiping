<?php

namespace App\Services;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(private readonly PhoneNormalizer $phones) {}

    public function search(?string $name, ?string $phone): CustomerSearchResult
    {
        $name = trim((string) $name);
        $phone = trim((string) $phone);
        $phoneUnsearchable = false;

        $query = Customer::query()
            ->with('phones')
            ->orderBy('name')
            ->orderBy('id');

        if ($name !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name);
            $query->whereRaw('customers.name like ? escape ?', ['%'.$escaped.'%', '\\']);
        }

        if ($phone !== '') {
            $normalized = $this->phones->normalize($phone);

            if ($normalized === null) {
                $phoneUnsearchable = true;
                $query->whereKey([]);
            } else {
                $query->whereHas('phones', function ($phones) use ($normalized): void {
                    $phones->where('normalized_number', $normalized);
                });
            }
        }

        $perPage = max(1, (int) config('smartpbx.customers.per_page', 25));

        return new CustomerSearchResult(
            $query->paginate($perPage)->withQueryString(),
            $phoneUnsearchable,
        );
    }

    /**
     * @param  array{name: string, company_name?: string|null, email?: string|null, notes?: string|null, phones: list<array{raw_number: string, label?: string|null}>}  $attributes
     */
    public function create(array $attributes): Customer
    {
        return DB::transaction(function () use ($attributes): Customer {
            $phones = $this->preparedPhones($attributes['phones']);

            $customer = Customer::query()->create([
                'name' => $attributes['name'],
                'company_name' => $this->blankToNull($attributes['company_name'] ?? null),
                'email' => $this->blankToNull($attributes['email'] ?? null),
                'notes' => $this->blankToNull($attributes['notes'] ?? null),
                'is_active' => true,
            ]);

            $this->insertPhones($customer, $phones);

            return $customer->load('phones');
        });
    }

    /**
     * Saves an unknown caller and attaches that customer to the operator's active call.
     *
     * @param  array{name: string, company_name?: string|null, email?: string|null, notes?: string|null, phones: list<array{raw_number: string, label?: string|null}>}  $attributes
     */
    public function registerUnknownCaller(User $operator, string $linkedid, array $attributes): Customer
    {
        $rawPhone = trim((string) ($attributes['phones'][0]['raw_number'] ?? ''));
        $normalized = $this->phones->normalize($rawPhone);
        $call = Call::query()
            ->where('user_id', $operator->id)
            ->where('asterisk_linkedid', $linkedid)
            ->whereNull('customer_id')
            ->whereIn('status', [CallStatus::Ringing, CallStatus::Answered])
            ->first();

        if ($call === null) {
            throw ValidationException::withMessages([
                'linkedid' => 'No active unknown call matches this popup.',
            ]);
        }

        $callNumber = $call->caller_number_normalized
            ?: $this->phones->normalize($call->caller_number_raw ?: $call->phone);

        if ($normalized === null || $normalized !== $callNumber) {
            throw ValidationException::withMessages([
                'phone' => 'The phone number does not match the active call.',
            ]);
        }

        $customer = $this->create($attributes);
        $call->customer_id = $customer->id;
        $call->save();

        return $customer;
    }

    /**
     * @param  array{name: string, company_name?: string|null, email?: string|null, notes?: string|null, phones: list<array{raw_number: string, label?: string|null}>}  $attributes
     */
    public function update(Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($customer, $attributes): Customer {
            $phones = $this->preparedPhones($attributes['phones'], $customer);

            $customer->update([
                'name' => $attributes['name'],
                'company_name' => $this->blankToNull($attributes['company_name'] ?? null),
                'email' => $this->blankToNull($attributes['email'] ?? null),
                'notes' => $this->blankToNull($attributes['notes'] ?? null),
            ]);

            $customer->phones()->delete();
            $this->insertPhones($customer, $phones);

            return $customer->load('phones');
        });
    }

    /**
     * Removes the customer and their phone numbers. Call rows stay, with the customer link cleared.
     */
    public function setActive(Customer $customer, bool $active): Customer
    {
        $customer->update(['is_active' => $active]);

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $customer->calls()->update(['customer_id' => null]);
            $customer->phones()->delete();
            $customer->delete();
        });
    }

    /**
     * @param  list<array{raw_number?: string|null, label?: string|null}>  $phones
     * @return array<string, string>
     */
    public function phoneErrors(array $phones, ?Customer $ignore = null): array
    {
        try {
            $this->preparedPhones($phones, $ignore);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }

    /**
     * @param  list<array{raw_number?: string|null, label?: string|null}>  $phones
     * @return list<array{raw_number: string, normalized_number: string, label: string|null}>
     */
    private function preparedPhones(array $phones, ?Customer $ignore = null): array
    {
        if ($phones === []) {
            throw ValidationException::withMessages([
                'phones' => 'Add at least one phone number.',
            ]);
        }

        $errors = [];
        $prepared = [];
        $seen = [];

        foreach (array_values($phones) as $index => $phone) {
            $raw = trim((string) ($phone['raw_number'] ?? ''));
            $normalized = $this->phones->normalize($raw);

            if ($normalized === null) {
                $errors["phones.$index.raw_number"] = 'Enter a valid phone number.';

                continue;
            }

            if (isset($seen[$normalized])) {
                $errors["phones.$index.raw_number"] = 'This number is entered more than once.';

                continue;
            }

            $seen[$normalized] = $index;
            $prepared[$index] = [
                'raw_number' => $raw,
                'normalized_number' => $normalized,
                'label' => $this->blankToNull($phone['label'] ?? null),
            ];
        }

        if ($prepared !== []) {
            $existing = CustomerPhone::query()
                ->whereIn('normalized_number', array_column($prepared, 'normalized_number'))
                ->when($ignore !== null, fn ($query) => $query->where('customer_id', '!=', $ignore->id))
                ->with('customer:id,name')
                ->get()
                ->keyBy('normalized_number');

            foreach ($prepared as $index => $phone) {
                $match = $existing->get($phone['normalized_number']);

                if ($match !== null) {
                    $errors["phones.$index.raw_number"] = 'This number already belongs to '.$match->customer->name.'.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($prepared);
    }

    /**
     * @param  list<array{raw_number: string, normalized_number: string, label: string|null}>  $phones
     */
    private function insertPhones(Customer $customer, array $phones): void
    {
        try {
            $customer->phones()->createMany($phones);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'phones.0.raw_number' => 'This number already belongs to another customer.',
            ]);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
