<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Call log') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="GET" action="{{ route('calls.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <x-input-label for="phone" :value="__('Phone')" />
                            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="$filters['phone'] ?? ''" maxlength="40" />
                        </div>
                        <div>
                            <x-input-label for="extension" :value="__('Extension')" />
                            <x-text-input id="extension" name="extension" type="text" class="mt-1 block w-full" :value="$filters['extension'] ?? ''" maxlength="20" />
                        </div>
                        <div>
                            <x-input-label for="direction" :value="__('Direction')" />
                            <select id="direction" name="direction" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">{{ __('Any') }}</option>
                                @foreach ($directions as $direction)
                                    <option value="{{ $direction->value }}" @selected(($filters['direction'] ?? '') === $direction->value)>
                                        {{ $direction->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="status" :value="__('Status')" />
                            <select id="status" name="status" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">{{ __('Any') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status['value'] }}" @selected(($filters['status'] ?? '') === $status['value'])>
                                        {{ $status['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="started_from" :value="__('From')" />
                            <x-text-input id="started_from" name="started_from" type="date" class="mt-1 block w-full" :value="$filters['started_from'] ?? ''" />
                        </div>
                        <div>
                            <x-input-label for="started_to" :value="__('To')" />
                            <x-text-input id="started_to" name="started_to" type="date" class="mt-1 block w-full" :value="$filters['started_to'] ?? ''" />
                        </div>
                        <div class="sm:col-span-2 flex items-end gap-3">
                            <x-primary-button type="submit">{{ __('Filter') }}</x-primary-button>
                            <a href="{{ route('calls.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                {{ __('Clear') }}
                            </a>
                        </div>
                    </form>

                    @if ($errors->any())
                        <div class="mt-4 text-sm text-red-600">
                            {{ $errors->first() }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="text-sm text-gray-600 mb-4">
                        {{ trans_choice(':count call|:count calls', $calls->total(), ['count' => $calls->total()]) }}
                    </p>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead>
                                <tr class="border-b border-gray-200 text-gray-500">
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Started') }}</th>
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Phone') }}</th>
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Customer') }}</th>
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Extension') }}</th>
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Direction') }}</th>
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                                    <th scope="col" class="py-2 pe-4 font-medium">{{ __('Duration') }}</th>
                                    <th scope="col" class="py-2 font-medium">{{ __('Call ID') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($calls as $call)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pe-4 whitespace-nowrap">{{ $call->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
                                        <td class="py-2 pe-4 whitespace-nowrap">{{ $call->phone }}</td>
                                        <td class="py-2 pe-4">{{ $call->customer->name ?? __('Unknown') }}</td>
                                        <td class="py-2 pe-4 whitespace-nowrap">{{ $call->extension ?? '—' }}</td>
                                        <td class="py-2 pe-4 whitespace-nowrap">{{ $call->direction->label() }}</td>
                                        <td class="py-2 pe-4 whitespace-nowrap">{{ $call->status->label() }}</td>
                                        <td class="py-2 pe-4 whitespace-nowrap">{{ $call->formattedDuration() }}</td>
                                        <td class="py-2 whitespace-nowrap">{{ $call->unique_id ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-6 text-gray-500">{{ __('No calls match these filters.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $calls->links() }}
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="font-medium text-gray-800">{{ __('Statuses') }}</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        @foreach ($statuses as $status)
                            <div>
                                <dt class="inline font-medium">{{ $status['label'] }}</dt>
                                <dd class="inline text-gray-600">— {{ $status['description'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <h3 class="mt-6 font-medium text-gray-800">{{ __('Directions') }}</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        @foreach ($directions as $direction)
                            <div>
                                <dt class="inline font-medium">{{ $direction->label() }}</dt>
                                <dd class="inline text-gray-600">— {{ $direction->description() }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
