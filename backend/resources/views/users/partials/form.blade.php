@php
    $selectedRole = old('role', $user?->role?->value ?? \App\Enums\UserRole::Operator->value);
    $active = filter_var(old('is_active', $user?->is_active ?? true), FILTER_VALIDATE_BOOLEAN);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div>
        <x-input-label for="name" value="Name" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user?->name)" required maxlength="120" />
        <x-input-error class="mt-2" :messages="$errors->get('name')" />
    </div>

    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user?->email)" required />
        <x-input-error class="mt-2" :messages="$errors->get('email')" />
    </div>

    <div>
        <x-input-label for="role" value="Role" />
        <select id="role" name="role" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            @foreach (\App\Enums\UserRole::cases() as $role)
                <option value="{{ $role->value }}" @selected($selectedRole === $role->value)>{{ $role->label() }}</option>
            @endforeach
        </select>
        <x-input-error class="mt-2" :messages="$errors->get('role')" />
    </div>

    <div>
        <label for="is_active" class="inline-flex items-center">
            <input id="is_active" type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked($active)>
            <span class="ms-2 text-sm text-gray-600">Active</span>
        </label>
        <x-input-error class="mt-2" :messages="$errors->get('is_active')" />
    </div>

    <div>
        <x-input-label for="password" :value="$user ? 'New password' : 'Password'" />
        <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
        <p class="mt-1 text-sm text-gray-600">At least 12 characters.{{ $user ? ' Leave blank to keep the current password.' : '' }}</p>
        <x-input-error class="mt-2" :messages="$errors->get('password')" />
    </div>

    <div>
        <x-input-label for="password_confirmation" value="Confirm password" />
        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
    </div>

    <div class="flex items-center gap-4">
        <x-primary-button>Save</x-primary-button>
        <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</form>
