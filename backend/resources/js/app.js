import Alpine from 'alpinejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;
window.Alpine = Alpine;

function startEcho() {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        return null;
    }

    if (!window.Echo) {
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
            },
        });
    }

    return window.Echo;
}

document.addEventListener('alpine:init', () => {
    Alpine.data('callPopup', (userId) => ({
        call: null,
        hideTimer: null,
        form: { name: '', company_name: '', notes: '' },
        saving: false,
        saved: false,
        error: '',
        timerLabel: '',
        tickHandle: null,
        init() {
            const echo = startEcho();

            if (!echo) {
                console.log('Call popup socket is not configured.');
                return;
            }

            console.log('Subscribed to operator channel:', `private-operators.${userId}`);

            echo.private(`operators.${userId}`)
                .listen('.CallPopupUpdated', (payload) => {
                    console.log('Call event received:', payload);
                    this.onCall(payload.call);
                });
        },
        onCall(call) {
            if (!call) {
                return;
            }

            if (this.hideTimer) {
                clearTimeout(this.hideTimer);
                this.hideTimer = null;
            }

            if (this.call?.id !== call.id) {
                this.form = { name: '', company_name: '', notes: '' };
                this.saved = false;
                this.error = '';
                this.timerLabel = '';
            }

            this.call = call;
            this.syncTimer();

            if (['ended', 'missed', 'busy', 'failed', 'incomplete'].includes(call.status)) {
                this.hideTimer = setTimeout(() => {
                    if (this.call?.id === call.id) {
                        this.stopTimer();
                        this.timerLabel = '';
                        this.call = null;
                    }
                }, 2500);
            }
        },
        dismiss() {
            this.stopTimer();
            this.timerLabel = '';
            this.call = null;
        },
        destroy() {
            this.stopTimer();

            if (this.hideTimer) {
                clearTimeout(this.hideTimer);
                this.hideTimer = null;
            }
        },
        syncTimer() {
            if (this.call?.status === 'answered' && this.call.answered_at) {
                this.updateTimer();

                if (!this.tickHandle) {
                    this.tickHandle = setInterval(() => this.updateTimer(), 1000);
                }

                return;
            }

            this.stopTimer();

            if (this.call?.status !== 'ended') {
                this.timerLabel = '';
            }
        },
        updateTimer() {
            const started = Date.parse(this.call?.answered_at ?? '');

            if (!this.call || this.call.status !== 'answered' || Number.isNaN(started)) {
                return;
            }

            const seconds = Math.max(0, Math.floor((Date.now() - started) / 1000));
            const minutes = Math.floor(seconds / 60);
            const remainder = seconds % 60;
            this.timerLabel = `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
        },
        stopTimer() {
            if (this.tickHandle) {
                clearInterval(this.tickHandle);
                this.tickHandle = null;
            }
        },
        get title() {
            if (!this.call?.customer) {
                return 'Unknown Caller';
            }

            if (this.call.customer.company_name) {
                return `${this.call.customer.name} · ${this.call.customer.company_name}`;
            }

            return this.call.customer.name;
        },
        get statusLabel() {
            const labels = {
                ringing: 'Ringing',
                answered: 'Answered',
                ended: 'Ended',
                missed: 'Missed',
                busy: 'Busy',
                failed: 'Failed',
                incomplete: 'Incomplete',
            };

            return labels[this.call?.status] || this.call?.status || '';
        },
        get directionLabel() {
            if (this.call?.direction === 'inbound') {
                return 'Inbound';
            }

            if (this.call?.direction === 'outbound') {
                return 'Outbound';
            }

            return '';
        },
        get extensionLabel() {
            return this.call?.direction === 'outbound' ? 'From extension' : 'To extension';
        },
        get initials() {
            const source = (this.call?.customer?.name || this.call?.caller_name || '').trim();
            const parts = source.split(/\s+/).filter(Boolean);

            if (parts.length === 0) {
                return '';
            }

            return `${parts[0][0] || ''}${parts[1]?.[0] || ''}`.toUpperCase();
        },
        get showForm() {
            return Boolean(this.call)
                && !this.call.customer
                && ['ringing', 'answered'].includes(this.call.status);
        },
        async saveCaller() {
            if (!this.call || this.saving) {
                return;
            }

            this.saving = true;
            this.error = '';

            try {
                const response = await fetch('/api/customers/quick-add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                    },
                    body: JSON.stringify({
                        name: this.form.name,
                        company_name: this.form.company_name,
                        notes: this.form.notes,
                        phone: this.call.caller_number,
                        linkedid: this.call.linkedid,
                    }),
                });
                const body = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const messages = body.errors ? Object.values(body.errors).flat() : [];
                    this.error = messages[0] || 'Could not save this caller.';
                    return;
                }

                this.call = {
                    ...this.call,
                    customer: body.customer,
                };
                this.saved = true;
            } catch {
                this.error = 'Could not save this caller.';
            } finally {
                this.saving = false;
            }
        },
    }));
});

Alpine.start();
