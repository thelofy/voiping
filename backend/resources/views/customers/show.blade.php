<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $customer->name }}
            </h2>
            <a href="{{ route('customers.edit', $customer) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                Edit
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <div class="p-6 bg-white shadow sm:rounded-lg space-y-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Status</h3>
                    <p class="mt-2 text-sm text-gray-900">{{ $customer->is_active ? 'Active' : 'Inactive' }}</p>
                    <form method="POST" action="{{ route('customers.active', $customer) }}" class="mt-3">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="is_active" value="{{ $customer->is_active ? '0' : '1' }}">
                        <x-secondary-button>{{ $customer->is_active ? 'Deactivate' : 'Activate' }}</x-secondary-button>
                    </form>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-gray-500">Company</h3>
                    <p class="mt-2 text-sm text-gray-900">{{ $customer->company_name ?: '—' }}</p>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-gray-500">Email</h3>
                    <p class="mt-2 text-sm text-gray-900">{{ $customer->email ?: '—' }}</p>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-gray-500">Phone numbers</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach ($customer->phones as $phone)
                            <li class="text-sm text-gray-900">
                                {{ $phone->raw_number }}
                                @if ($phone->label)
                                    <span class="text-gray-500">({{ $phone->label }})</span>
                                @endif
                                <span class="block text-gray-500">Lookup number {{ $phone->normalized_number }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-medium text-gray-500">Notes</h3>
                    <p class="mt-2 text-sm text-gray-900 whitespace-pre-wrap">{{ $customer->notes ?: '—' }}</p>
                </div>

                <div class="text-sm text-gray-500">
                    <p>Created {{ $customer->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>
                    <p>Updated {{ $customer->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>
                </div>

                <a href="{{ route('customers.index') }}" class="inline-flex text-sm text-gray-600 hover:text-gray-900">Back to customers</a>
            </div>

            @can('delete', $customer)
                <div class="p-6 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-medium text-gray-900">Delete customer</h3>
                    <p class="mt-2 text-sm text-gray-600">Deleting this customer removes their phone numbers. Call history stays, and those calls no longer name this customer.</p>

                    <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="mt-4 space-y-4">
                        @csrf
                        @method('DELETE')

                        <label for="confirmed" class="inline-flex items-center">
                            <input id="confirmed" type="checkbox" name="confirmed" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked(old('confirmed'))>
                            <span class="ms-2 text-sm text-gray-600">I confirm this customer should be deleted.</span>
                        </label>
                        <x-input-error class="mt-2" :messages="$errors->get('confirmed')" />

                        <x-danger-button>Delete customer</x-danger-button>
                    </form>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
