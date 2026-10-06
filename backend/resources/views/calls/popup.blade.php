<div
    x-data="callPopup({{ auth()->id() }})"
    x-show="call"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 -translate-y-1"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-1"
    class="pointer-events-none fixed inset-0 z-40 flex items-center justify-center p-4"
>
    <div
        class="pointer-events-auto max-h-[calc(100vh-2rem)] w-80 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg"
        role="dialog"
        aria-labelledby="call-popup-title"
    >
        <div
            class="border-s-2 px-3 py-3"
            :class="{
                'border-amber-400': call?.status === 'ringing',
                'border-emerald-500': call?.status === 'answered',
                'border-rose-400': ['missed', 'busy', 'failed'].includes(call?.status),
                'border-slate-300': ['ended', 'incomplete'].includes(call?.status),
            }"
        >
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium uppercase tracking-wide"
                            :class="{
                                'animate-pulse bg-amber-50 text-amber-700': call?.status === 'ringing',
                                'bg-emerald-50 text-emerald-700': call?.status === 'answered',
                                'bg-rose-50 text-rose-700': ['missed', 'busy', 'failed'].includes(call?.status),
                                'bg-slate-100 text-slate-500': ['ended', 'incomplete'].includes(call?.status),
                            }"
                            x-text="statusLabel"
                        ></span>
                        <span x-show="directionLabel" x-cloak class="text-[11px] font-medium uppercase tracking-wide text-slate-400" x-text="directionLabel"></span>
                        <span
                            x-show="timerLabel && (call?.status === 'answered' || call?.status === 'ended')"
                            x-cloak
                            class="font-mono text-xs tabular-nums text-slate-500"
                            x-text="timerLabel"
                        ></span>
                    </div>
                    <h2 id="call-popup-title" class="mt-1 truncate text-sm font-semibold text-slate-900" x-text="title"></h2>
                    <p
                        x-show="call?.caller_name && call.caller_name !== title"
                        x-cloak
                        class="truncate text-xs text-slate-500"
                        x-text="call?.caller_name"
                    ></p>
                    <p class="mt-1 text-xs text-slate-500">
                        <span class="font-mono text-sm font-medium text-slate-800" x-text="call?.caller_number || ''"></span>
                        <span class="mx-1 text-slate-300">·</span>
                        <span x-text="extensionLabel"></span>
                        <span class="font-medium text-slate-700" x-text="call?.extension || '—'"></span>
                    </p>
                    <p x-show="call?.customer?.notes" x-cloak class="mt-1 line-clamp-2 text-xs text-slate-500" x-text="call?.customer?.notes"></p>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-700" @click="dismiss" aria-label="Close">
                    <span aria-hidden="true" class="text-lg leading-none">&times;</span>
                </button>
            </div>

            <p
                x-show="saved"
                x-cloak
                class="mt-2 text-xs font-medium text-emerald-700"
            >Customer created and linked</p>

            <form x-show="showForm" x-cloak class="mt-3 space-y-2" @submit.prevent="saveCaller">
                <input x-model="form.name" required maxlength="160" placeholder="Name" aria-label="Name" class="block w-full rounded-md border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <input x-model="form.company_name" maxlength="160" placeholder="Company" aria-label="Company" class="block w-full rounded-md border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <input x-model="form.notes" maxlength="500" placeholder="Note" aria-label="Note" class="block w-full rounded-md border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <input :value="call?.caller_number || ''" readonly aria-label="Phone" class="block w-full cursor-not-allowed rounded-md border-slate-200 bg-slate-50 py-1.5 font-mono text-sm text-slate-500">
                <p x-show="error" x-text="error" x-cloak class="text-xs text-rose-600"></p>
                <button
                    type="submit"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-60"
                    :disabled="saving"
                >
                    <svg x-show="saving" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                    </svg>
                    <span x-text="saving ? 'Saving…' : 'Save customer'"></span>
                </button>
            </form>
        </div>
    </div>
</div>
