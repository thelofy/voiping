<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p>Signed in as {{ $user->name }}.</p>
                    <p class="mt-2 text-sm text-gray-600">{{ $user->role->label() }}</p>

                    <div class="mt-6 flex flex-col items-start gap-3">
                        <a href="{{ route('customers.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                            Customers
                        </a>
                        <a href="{{ route('calls.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                            Call log
                        </a>

                        @if ($user->isAdministrator())
                            <a href="{{ route('users.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                Manage users
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
