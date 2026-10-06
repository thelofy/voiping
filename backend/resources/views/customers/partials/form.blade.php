@php
    $existingPhones = isset($customer)
        ? $customer->phones->map(fn ($phone) => [
            'raw_number' => $phone->raw_number,
            'label' => $phone->label,
        ])->all()
        : [];
    $phoneRows = old('phones', $existingPhones);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div>
        <x-input-label for="name" value="Name" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $customer?->name ?? '')" required maxlength="160" />
        <x-input-error class="mt-2" :messages="$errors->get('name')" />
    </div>

    <div>
        <x-input-label for="company_name" value="Company" />
        <x-text-input id="company_name" name="company_name" type="text" class="mt-1 block w-full" :value="old('company_name', $customer?->company_name ?? '')" maxlength="160" />
        <x-input-error class="mt-2" :messages="$errors->get('company_name')" />
    </div>

    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $customer?->email ?? '')" maxlength="255" />
        <x-input-error class="mt-2" :messages="$errors->get('email')" />
    </div>

    <div>
        <x-input-label for="notes" value="Notes" />
        <textarea id="notes" name="notes" rows="4" maxlength="500" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes', $customer?->notes ?? '') }}</textarea>
        <p class="mt-1 text-sm text-gray-600">Optional. Plain text, up to 500 characters.</p>
        <x-input-error class="mt-2" :messages="$errors->get('notes')" />
    </div>

    <fieldset class="space-y-4">
        <legend class="text-sm font-medium text-gray-700">Phone numbers</legend>
        <p class="text-sm text-gray-600">At least one number. Up to five. A number can belong to only one customer.</p>
        <x-input-error :messages="$errors->get('phones')" />

        @for ($i = 0; $i < 5; $i++)
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-input-label :for="'phone_'.$i" :value="'Number '.($i + 1)" />
                    <x-text-input :id="'phone_'.$i" :name="'phones['.$i.'][raw_number]'" type="text" class="mt-1 block w-full" :value="$phoneRows[$i]['raw_number'] ?? ''" maxlength="40" autocomplete="off" />
                    <x-input-error class="mt-2" :messages="$errors->get('phones.'.$i.'.raw_number')" />
                </div>
                <div>
                    <x-input-label :for="'label_'.$i" value="Label" />
                    <x-text-input :id="'label_'.$i" :name="'phones['.$i.'][label]'" type="text" class="mt-1 block w-full" :value="$phoneRows[$i]['label'] ?? ''" maxlength="40" placeholder="mobile, office, other" />
                    <x-input-error class="mt-2" :messages="$errors->get('phones.'.$i.'.label')" />
                </div>
            </div>
        @endfor
    </fieldset>

    <div class="flex items-center gap-4">
        <x-primary-button>Save</x-primary-button>
        <a href="{{ $cancel }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>
