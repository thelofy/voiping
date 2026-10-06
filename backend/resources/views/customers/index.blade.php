<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Customers') }}
            </h2>
            <a href="{{ route('customers.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                New customer
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <form method="GET" action="{{ route('customers.index') }}" class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="$filters['name']" maxlength="160" />
                        <x-input-error class="mt-2" :messages="$errors->get('name')" />
                    </div>
                    <div>
                        <x-input-label for="phone" value="Phone" />
                        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="$filters['phone']" maxlength="40" />
                        <x-input-error class="mt-2" :messages="$errors->get('phone')" />
                    </div>
                </div>
                <div class="mt-4 flex items-center gap-4">
                    <x-primary-button>Search</x-primary-button>
                    <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Clear</a>
                </div>
            </form>

            @if ($phoneUnsearchable)
                <p class="text-sm text-red-600">That phone number cannot be searched. Enter the full number.</p>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($customers as $customer)
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        {{ $customer->name }}
                                        @if ($customer->company_name)
                                            <span class="block text-gray-500">{{ $customer->company_name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $customer->phones->pluck('raw_number')->join(', ') }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">{{ $customer->is_active ? 'Active' : 'Inactive' }}</td>
                                    <td class="px-6 py-4 text-sm text-right whitespace-nowrap">
                                        <a href="{{ route('customers.show', $customer) }}" class="text-indigo-600 hover:text-indigo-800">View</a>
                                        <a href="{{ route('customers.edit', $customer) }}" class="ms-4 text-indigo-600 hover:text-indigo-800">Edit</a>
                                        <form method="POST" action="{{ route('customers.active', $customer) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $customer->is_active ? '0' : '1' }}">
                                            <button type="submit" class="ms-4 text-indigo-600 hover:text-indigo-800">{{ $customer->is_active ? 'Deactivate' : 'Activate' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-sm text-gray-600">No customers found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($customers->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $customers->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
