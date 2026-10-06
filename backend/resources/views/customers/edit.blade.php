<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit customer') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-6 bg-white shadow sm:rounded-lg">
                @include('customers.partials.form', [
                    'action' => route('customers.update', $customer),
                    'method' => 'PUT',
                    'cancel' => route('customers.show', $customer),
                    'customer' => $customer,
                ])
            </div>
        </div>
    </div>
</x-app-layout>
