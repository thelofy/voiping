<?php

namespace App\Services;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Events\CallPopupUpdated;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Extension;
use App\Support\CallStateMachine;
use App\Support\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CallIngestService
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly CallStateMachine $states,
        private readonly CustomerMatcher $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(array $payload): IngestResult
    {
        try {
            $result = DB::transaction(fn (): IngestResult => $this->apply($payload));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = CallEvent::query()->where('event_id', $payload['event_id'])->first();

            if ($existing === null) {
                throw $exception;
            }

            $result = $this->fromStoredEvent($existing, duplicate: true);
        }

        $this->broadcast($result, (string) $payload['event_id']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(array $payload): IngestResult
    {
        $existing = CallEvent::query()
            ->where('event_id', $payload['event_id'])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $this->fromStoredEvent($existing, duplicate: true);
        }

        $extensionNumber = (string) $payload['call']['extension'];
        $eventType = (string) $payload['event_type'];
        $rawCaller = (string) ($payload['call']['caller_number'] ?? '');
        $normalized = $this->phones->normalize($rawCaller) ?? '';
        $occurredAt = Carbon::parse((string) $payload['occurred_at'])->utc();
        $extension = Extension::query()->where('number', $extensionNumber)->first();
        $operator = $extension?->user;

        if ($extension === null || ! $extension->is_active || $operator === null || ! $operator->is_active || ! $operator->isOperator()) {
            return $this->storeIgnored($payload, null);
        }

        if (! config('smartpbx.record_internal_calls') && $this->isInternalCaller($rawCaller, $normalized)) {
            return $this->storeIgnored($payload, null);
        }

        $call = Call::query()
            ->where('asterisk_linkedid', $payload['asterisk']['linkedid'])
            ->where('extension_number', $extensionNumber)
            ->lockForUpdate()
            ->first();

        if ($call === null && $eventType !== 'call.ringing') {
            return $this->storeIgnored($payload, null);
        }

        if ($call === null) {
            $call = $this->createRingingCall($payload, $extension, $normalized, $rawCaller, $occurredAt);

            if ($call->wasRecentlyCreated) {
                $this->recordOutboundLeg($call, $extension, $rawCaller, $occurredAt);

                return $this->storeApplied($payload, $call, broadcast: $this->shouldBroadcast($call));
            }
        }

        $next = $this->states->next($call->status, $eventType, $payload['call']['dial_status'] ?? null);

        if ($next === null) {
            $this->storeEvent($payload, $call->id, applied: false);

            return new IngestResult(
                httpStatus: 200,
                duplicate: false,
                applied: false,
                reason: null,
                call: $call,
                summarizeCall: false,
                broadcast: null,
                outcome: 'ignored',
            );
        }

        $changed = $next !== $call->status;

        if ($changed) {
            $this->applyTransition($call, $next, $payload, $occurredAt);
            $call->save();
            $this->mirrorInboundOntoOutbound($call);
        }

        $this->storeEvent($payload, $call->id, applied: true);

        return new IngestResult(
            httpStatus: 200,
            duplicate: false,
            applied: true,
            reason: null,
            call: $call,
            summarizeCall: false,
            broadcast: $changed && $this->shouldBroadcast($call) ? $this->broadcastPayload($call) : null,
            outcome: 'applied',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createRingingCall(
        array $payload,
        Extension $extension,
        string $normalized,
        string $rawCaller,
        Carbon $occurredAt,
    ): Call {
        $customerId = $this->customers->findByNormalizedNumber($normalized !== '' ? $normalized : null)?->id;

        try {
            $call = Call::query()->create([
                'customer_id' => $customerId,
                'user_id' => $extension->user_id,
                'phone' => $rawCaller,
                'caller_number_raw' => $rawCaller,
                'caller_number_normalized' => $normalized,
                'caller_name' => $this->blankToNull($payload['call']['caller_name'] ?? null),
                'extension' => $extension->number,
                'extension_number' => $extension->number,
                'extension_id' => $extension->id,
                'direction' => $this->direction($payload),
                'status' => CallStatus::Ringing,
                'unique_id' => $payload['asterisk']['uniqueid'],
                'asterisk_linkedid' => $payload['asterisk']['linkedid'],
                'asterisk_uniqueid' => $payload['asterisk']['uniqueid'],
                'started_at' => $occurredAt,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $call = Call::query()
                ->where('asterisk_linkedid', $payload['asterisk']['linkedid'])
                ->where('extension_number', $extension->number)
                ->lockForUpdate()
                ->first();

            if ($call === null) {
                throw $exception;
            }
        }

        return $call;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyTransition(Call $call, CallStatus $next, array $payload, Carbon $occurredAt): void
    {
        $call->status = $next;
        $call->asterisk_uniqueid = (string) $payload['asterisk']['uniqueid'];
        $call->unique_id = (string) $payload['asterisk']['uniqueid'];

        if ($next === CallStatus::Answered && $call->answered_at === null) {
            $call->answered_at = $occurredAt;
        }

        if (! $next->isTerminal()) {
            return;
        }

        $call->ended_at = $occurredAt;
        $seconds = $call->answered_at === null
            ? null
            : (int) round($occurredAt->diffInSeconds($call->answered_at, true));
        $call->duration_seconds = $seconds;
        $call->duration = $seconds;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeApplied(array $payload, Call $call, bool $broadcast): IngestResult
    {
        $this->storeEvent($payload, $call->id, applied: true);

        return new IngestResult(
            httpStatus: 200,
            duplicate: false,
            applied: true,
            reason: null,
            call: $call,
            summarizeCall: false,
            broadcast: $broadcast ? $this->broadcastPayload($call) : null,
            outcome: 'applied',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeIgnored(array $payload, ?int $callId): IngestResult
    {
        $this->storeEvent($payload, $callId, applied: false);

        return new IngestResult(
            httpStatus: 202,
            duplicate: false,
            applied: false,
            reason: 'ignored',
            call: null,
            summarizeCall: false,
            broadcast: null,
            outcome: 'ignored',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeEvent(array $payload, ?int $callId, bool $applied): CallEvent
    {
        return CallEvent::query()->create([
            'event_id' => $payload['event_id'],
            'call_id' => $callId,
            'event_type' => $payload['event_type'],
            'payload' => $this->canonicalPayload($payload),
            'applied' => $applied,
        ]);
    }

    private function fromStoredEvent(CallEvent $event, bool $duplicate): IngestResult
    {
        $call = $event->call_id === null ? null : Call::query()->find($event->call_id);

        if ($call === null) {
            return new IngestResult(
                httpStatus: 202,
                duplicate: $duplicate,
                applied: false,
                reason: 'ignored',
                call: null,
                summarizeCall: true,
                broadcast: null,
                outcome: 'duplicate',
            );
        }

        return new IngestResult(
            httpStatus: 200,
            duplicate: $duplicate,
            applied: $event->applied,
            reason: null,
            call: $call,
            summarizeCall: true,
            broadcast: null,
            outcome: 'duplicate',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonicalPayload(array $payload): array
    {
        return [
            'event_id' => $payload['event_id'],
            'event_type' => $payload['event_type'],
            'occurred_at' => $payload['occurred_at'],
            'asterisk' => [
                'uniqueid' => $payload['asterisk']['uniqueid'],
                'linkedid' => $payload['asterisk']['linkedid'],
                'channel' => $payload['asterisk']['channel'],
            ],
            'call' => [
                'caller_number' => $payload['call']['caller_number'] ?? '',
                'caller_name' => $payload['call']['caller_name'] ?? null,
                'extension' => $payload['call']['extension'],
                'dial_status' => $payload['call']['dial_status'] ?? null,
            ],
        ];
    }

    /**
     * The dialed extension is inbound. A second row records the same call as outbound for the caller.
     *
     * @param  array<string, mixed>  $payload
     */
    private function direction(array $payload): CallDirection
    {
        $given = $payload['call']['direction'] ?? null;

        return is_string($given) ? (CallDirection::tryFrom($given) ?? CallDirection::Inbound) : CallDirection::Inbound;
    }

    private function recordOutboundLeg(Call $inbound, Extension $destination, string $rawCaller, Carbon $occurredAt): void
    {
        if ($inbound->direction !== CallDirection::Inbound) {
            return;
        }

        $callerExtension = Extension::query()->where('number', $rawCaller)->first();
        $operator = $callerExtension?->user;

        if (
            $callerExtension === null
            || $callerExtension->number === $destination->number
            || ! $callerExtension->is_active
            || $operator === null
            || ! $operator->is_active
            || ! $operator->isOperator()
        ) {
            return;
        }

        $alreadyStored = Call::query()
            ->where('asterisk_linkedid', $inbound->asterisk_linkedid)
            ->where('extension_number', $callerExtension->number)
            ->exists();

        if ($alreadyStored) {
            return;
        }

        try {
            Call::query()->create([
                'customer_id' => null,
                'user_id' => $callerExtension->user_id,
                'phone' => $destination->number,
                'caller_number_raw' => $destination->number,
                'caller_number_normalized' => $this->phones->normalize($destination->number) ?? '',
                'caller_name' => null,
                'extension' => $callerExtension->number,
                'extension_number' => $callerExtension->number,
                'extension_id' => $callerExtension->id,
                'direction' => CallDirection::Outbound,
                'status' => CallStatus::Ringing,
                'unique_id' => null,
                'asterisk_linkedid' => $inbound->asterisk_linkedid,
                'asterisk_uniqueid' => $inbound->asterisk_uniqueid,
                'started_at' => $occurredAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The caller leg was stored by a concurrent ringing event.
        }
    }

    private function mirrorInboundOntoOutbound(Call $inbound): void
    {
        if ($inbound->direction !== CallDirection::Inbound) {
            return;
        }

        $outbound = Call::query()
            ->where('asterisk_linkedid', $inbound->asterisk_linkedid)
            ->where('direction', CallDirection::Outbound)
            ->lockForUpdate()
            ->first();

        if ($outbound === null) {
            return;
        }

        $outbound->status = $inbound->status;
        $outbound->answered_at = $inbound->answered_at;
        $outbound->ended_at = $inbound->ended_at;
        $outbound->duration_seconds = $inbound->duration_seconds;
        $outbound->duration = $inbound->duration;
        $outbound->save();
    }

    private function shouldBroadcast(Call $call): bool
    {
        return $call->direction !== CallDirection::Outbound;
    }

    private function isInternalCaller(string $raw, string $normalized): bool
    {
        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        $candidates = array_values(array_unique(array_filter(
            [$raw, $digits, $normalized],
            fn (string $value): bool => $value !== '',
        )));

        if ($candidates === []) {
            return false;
        }

        return Extension::query()->whereIn('number', $candidates)->exists();
    }

    /**
     * @return array{user_id: int, call: array<string, mixed>}|null
     */
    private function broadcastPayload(Call $call): ?array
    {
        if ($call->user_id === null) {
            return null;
        }

        $call->loadMissing('customer');
        $customer = $call->customer;

        return [
            'user_id' => $call->user_id,
            'call' => [
                'id' => $call->id,
                'linkedid' => $call->asterisk_linkedid,
                'status' => $call->status->value,
                'direction' => $call->direction->value,
                'extension' => $call->extension_number,
                'caller_number' => $call->caller_number_raw,
                'caller_number_normalized' => $call->caller_number_normalized,
                'caller_name' => $call->caller_name ?? '',
                'customer' => $customer === null ? null : [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'company_name' => $customer->company_name,
                    'notes' => $customer->notes,
                ],
                'started_at' => $this->iso($call->started_at),
                'answered_at' => $this->iso($call->answered_at),
                'history' => $this->history($call),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(Call $call): array
    {
        if ($call->caller_number_normalized === '') {
            return [];
        }

        return Call::query()
            ->where('caller_number_normalized', $call->caller_number_normalized)
            ->whereKeyNot($call->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Call $previous): array => [
                'id' => $previous->id,
                'status' => $previous->status->value,
                'started_at' => $this->iso($previous->started_at),
                'duration_seconds' => $previous->duration_seconds,
            ])
            ->all();
    }

    private function broadcast(IngestResult $result, string $eventId): void
    {
        if ($result->broadcast === null) {
            return;
        }

        try {
            event(new CallPopupUpdated($result->broadcast['user_id'], $result->broadcast['call']));
        } catch (Throwable $exception) {
            Log::error('asterisk.ingest.broadcast_failed', [
                'event_id' => $eventId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function iso(?Carbon $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
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
