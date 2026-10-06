<?php

namespace Tests\Feature;

use App\Broadcasting\OperatorPopupChannel;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\UserRole;
use App\Events\CallPopupUpdated;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class AsteriskEventIngestTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Extension $extension;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('smartpbx.record_internal_calls', false);

        $this->operator = User::factory()->operator()->create();
        $this->extension = Extension::factory()->create([
            'number' => '1001',
            'user_id' => $this->operator->id,
        ]);
        $this->token = User::factory()->inactive()->create()->createToken('connector', ['asterisk:ingest'])->plainTextToken;
    }

    public function test_missing_and_session_credentials_are_rejected(): void
    {
        $this->postJson('/api/internal/v1/asterisk/events', $this->payload())
            ->assertUnauthorized();

        $this->actingAs($this->operator)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload())
            ->assertUnauthorized();
    }

    public function test_a_token_without_the_ingest_ability_is_forbidden(): void
    {
        $token = User::factory()->inactive()->create()->createToken('connector', ['read'])->plainTextToken;
        $wildcard = User::factory()->inactive()->create()->createToken('connector', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertForbidden();
        $this->withToken($wildcard)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertForbidden();
    }

    public function test_ringing_creates_one_call_and_matches_the_customer(): void
    {
        Event::fake([CallPopupUpdated::class]);
        $log = $this->captureLogs();

        $customer = Customer::factory()->create([
            'name' => 'Sara Ahmadi',
            'company_name' => 'North Office',
            'notes' => 'Prefers morning calls',
        ]);
        CustomerPhone::query()->create([
            'customer_id' => $customer->id,
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload())
            ->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('applied', true)
            ->assertJsonPath('call.status', 'ringing')
            ->assertJsonPath('call.extension', '1001')
            ->assertJsonPath('call.customer.id', $customer->id)
            ->assertJsonPath('call.customer.name', 'Sara Ahmadi');

        $this->assertDatabaseCount('calls', 1);
        $this->assertDatabaseCount('call_events', 1);
        $this->assertDatabaseHas('calls', [
            'caller_number_raw' => '09121234567',
            'caller_number_normalized' => '9121234567',
            'customer_id' => $customer->id,
            'user_id' => $this->operator->id,
            'extension_number' => '1001',
            'status' => 'ringing',
        ]);

        Event::assertDispatched(CallPopupUpdated::class, function (CallPopupUpdated $event): bool {
            return $event->userId === $this->operator->id
                && $event->call['linkedid'] === '1728000000.1'
                && $event->call['status'] === 'ringing'
                && $event->call['caller_number'] === '09121234567'
                && $event->call['caller_number_normalized'] === '9121234567'
                && $event->call['extension'] === '1001'
                && $event->call['customer']['name'] === 'Sara Ahmadi'
                && $event->call['customer']['company_name'] === 'North Office'
                && $event->call['customer']['notes'] === 'Prefers morning calls';
        });

        $records = collect($log->getRecords())->map(fn ($record): string => $record['message'].' '.json_encode($record['context']));
        $this->assertTrue($records->contains(fn (string $line): bool => str_contains($line, 'asterisk.ingest')));
        $this->assertFalse($records->contains(fn (string $line): bool => str_contains($line, $this->token) || str_contains($line, '09121234567')));
    }

    public function test_an_unknown_number_stores_the_call_with_a_null_customer(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload())
            ->assertOk()
            ->assertJsonPath('call.customer', null);

        $this->assertDatabaseHas('calls', [
            'caller_number_normalized' => '9121234567',
            'customer_id' => null,
        ]);
    }

    public function test_a_known_customer_matches_the_normalized_caller(): void
    {
        $customer = Customer::factory()->create(['name' => 'علی رضایی']);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'call.caller_number' => '09121234567',
            ]))
            ->assertOk()
            ->assertJsonPath('call.customer.id', $customer->id)
            ->assertJsonPath('call.customer.name', 'علی رضایی');

        $this->assertSame($customer->id, Call::query()->value('customer_id'));
    }

    public function test_a_formatted_caller_matches_the_stored_normalized_phone(): void
    {
        $customer = Customer::factory()->create(['name' => 'علی رضایی']);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'call.caller_number' => '+98 912 123 4567',
            ]))
            ->assertOk()
            ->assertJsonPath('call.customer.id', $customer->id);

        $this->assertDatabaseHas('calls', [
            'caller_number_raw' => '+98 912 123 4567',
            'caller_number_normalized' => '9121234567',
            'customer_id' => $customer->id,
        ]);
    }

    public function test_an_unlisted_mobile_is_stored_without_a_customer(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'call.caller_number' => '09000000000',
            ]))
            ->assertOk()
            ->assertJsonPath('call.customer', null);

        $this->assertDatabaseHas('calls', [
            'caller_number_raw' => '09000000000',
            'caller_number_normalized' => '9000000000',
            'customer_id' => null,
            'status' => 'ringing',
        ]);
    }

    public function test_an_inactive_customer_is_still_matched(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'علی رضایی',
            'is_active' => false,
        ]);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload())
            ->assertOk()
            ->assertJsonPath('call.customer.id', $customer->id);

        $this->assertFalse($customer->refresh()->is_active);
        $this->assertSame($customer->id, Call::query()->value('customer_id'));
    }

    public function test_matching_survives_answer_and_both_hangup_legs(): void
    {
        $customer = Customer::factory()->create(['name' => 'علی رضایی']);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);
        Extension::factory()->create([
            'number' => '1002',
            'user_id' => User::factory()->operator()->create()->id,
        ]);

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'occurred_at' => '2026-10-05T08:10:00Z',
            'asterisk.linkedid' => '1791226000.1',
            'asterisk.uniqueid' => '1791226000.2',
            'call.caller_number' => '+98 912 123 4567',
        ]))->assertOk()->assertJsonPath('call.status', 'ringing');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.answered',
            'occurred_at' => '2026-10-05T08:10:10Z',
            'asterisk.linkedid' => '1791226000.1',
            'asterisk.uniqueid' => '1791226000.2',
            'call.caller_number' => '+98 912 123 4567',
            'call.dial_status' => 'ANSWER',
        ]))->assertOk()->assertJsonPath('call.status', 'answered');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'occurred_at' => '2026-10-05T08:10:40Z',
            'asterisk.linkedid' => '1791226000.1',
            'asterisk.uniqueid' => '1791226000.3',
            'asterisk.channel' => 'PJSIP/1002-00000007',
            'call.caller_number' => '1002',
            'call.extension' => '1002',
            'call.dial_status' => '16',
        ]))->assertStatus(202);

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'occurred_at' => '2026-10-05T08:10:40Z',
            'asterisk.linkedid' => '1791226000.1',
            'asterisk.uniqueid' => '1791226000.2',
            'call.caller_number' => '+98 912 123 4567',
            'call.extension' => '1001',
            'call.dial_status' => '16',
        ]))->assertOk()->assertJsonPath('call.status', 'ended');

        $this->assertSame(1, Call::query()->count());
        $call = Call::query()->firstOrFail();
        $this->assertSame(CallStatus::Ended, $call->status);
        $this->assertSame('1791226000.1', $call->asterisk_linkedid);
        $this->assertSame($customer->id, $call->customer_id);
        $this->assertNotNull($call->ended_at);
        $this->assertSame(30, $call->duration_seconds);
    }

    public function test_a_repeated_event_id_does_not_change_the_call(): void
    {
        $this->freezeTime();

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertOk();
        $updatedAt = Call::query()->firstOrFail()->updated_at?->toDateTimeString();

        $this->travel(5)->minutes();

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload())
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('applied', true)
            ->assertJsonPath('call.status', 'ringing');

        $call = Call::query()->firstOrFail();
        $this->assertSame(1, Call::query()->count());
        $this->assertSame(1, CallEvent::query()->count());
        $this->assertSame($updatedAt, $call->updated_at?->toDateTimeString());
    }

    public function test_a_second_ringing_event_does_not_create_another_call(): void
    {
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertOk();

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'event_id' => (string) Str::uuid(),
            ]))
            ->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('applied', true)
            ->assertJsonPath('call.status', 'ringing');

        $this->assertSame(1, Call::query()->count());
        $this->assertSame(2, CallEvent::query()->count());
    }

    public function test_unknown_inactive_and_internal_calls_are_ignored(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload(['call.extension' => '9999']))
            ->assertStatus(202)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('reason', 'ignored');

        $this->assertSame(0, Call::query()->count());
        $this->assertDatabaseHas('call_events', ['applied' => false]);

        $this->extension->update(['is_active' => false]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'event_id' => (string) Str::uuid(),
            ]))
            ->assertStatus(202);

        $this->extension->update(['is_active' => true]);
        Extension::factory()->create(['number' => '1002', 'user_id' => User::factory()->operator()]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'event_id' => (string) Str::uuid(),
                'call.caller_number' => '1002',
            ]))
            ->assertStatus(202);

        $this->assertSame(0, Call::query()->count());
    }

    public function test_internal_calls_are_stored_when_recording_is_enabled(): void
    {
        Config::set('smartpbx.record_internal_calls', true);
        Extension::factory()->create(['number' => '1002', 'user_id' => User::factory()->operator()]);

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'call.caller_number' => '1002',
            ]))
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertDatabaseHas('calls', [
            'extension_number' => '1001',
            'caller_number_raw' => '1002',
            'direction' => 'inbound',
        ]);
        $this->assertDatabaseHas('calls', [
            'extension_number' => '1002',
            'phone' => '1001',
            'direction' => 'outbound',
        ]);
    }

    public function test_extension_101_calling_102_notifies_the_destination_operator(): void
    {
        Event::fake([CallPopupUpdated::class]);
        Config::set('smartpbx.record_internal_calls', true);

        $caller = User::factory()->operator()->create();
        $destination = User::factory()->operator()->create();
        Extension::factory()->create(['number' => '101', 'user_id' => $caller->id]);
        Extension::factory()->create(['number' => '102', 'user_id' => $destination->id]);

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'asterisk.linkedid' => '1791227125.195',
            'asterisk.uniqueid' => '1791227125.197',
            'asterisk.channel' => 'PJSIP/102-0000001a',
            'call.caller_number' => '101',
            'call.extension' => '102',
            'call.direction' => 'inbound',
        ]))->assertOk();

        $this->assertDatabaseHas('calls', [
            'phone' => '101',
            'extension_number' => '102',
            'direction' => CallDirection::Inbound->value,
            'user_id' => $destination->id,
        ]);
        $this->assertDatabaseHas('calls', [
            'phone' => '102',
            'extension_number' => '101',
            'direction' => CallDirection::Outbound->value,
            'user_id' => $caller->id,
        ]);

        Event::assertDispatched(CallPopupUpdated::class, function (CallPopupUpdated $event) use ($destination): bool {
            return $event->userId === $destination->id
                && $event->call['extension'] === '102'
                && $event->call['caller_number'] === '101';
        });
        Event::assertNotDispatched(CallPopupUpdated::class, function (CallPopupUpdated $event) use ($caller): bool {
            return $event->userId === $caller->id;
        });
    }

    public function test_answer_and_end_update_the_same_row_and_set_duration(): void
    {
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'occurred_at' => '2026-10-05T08:10:00Z',
        ]))->assertOk();

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.answered',
            'occurred_at' => '2026-10-05T08:10:10Z',
            'call.dial_status' => 'ANSWER',
        ]))->assertOk()->assertJsonPath('call.status', 'answered');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'occurred_at' => '2026-10-05T08:11:10Z',
            'call.dial_status' => 'ANSWER',
        ]))->assertOk()->assertJsonPath('call.status', 'ended');

        $this->assertDatabaseHas('calls', [
            'status' => 'ended',
            'duration_seconds' => 60,
        ]);
        $this->assertSame(1, Call::query()->count());
    }

    public function test_both_hangup_legs_end_one_call(): void
    {
        Config::set('smartpbx.record_internal_calls', true);
        Extension::factory()->create([
            'number' => '1002',
            'user_id' => User::factory()->operator()->create()->id,
        ]);

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'occurred_at' => '2026-10-05T08:10:00Z',
            'asterisk.uniqueid' => '1791224971.54',
            'asterisk.linkedid' => '1791224971.52',
            'asterisk.channel' => 'PJSIP/1001-00000008',
            'call.caller_number' => '1002',
            'call.extension' => '1001',
        ]))->assertOk();

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.answered',
            'occurred_at' => '2026-10-05T08:10:10Z',
            'asterisk.uniqueid' => '1791224971.54',
            'asterisk.linkedid' => '1791224971.52',
            'asterisk.channel' => 'PJSIP/1001-00000008',
            'call.caller_number' => '1002',
            'call.extension' => '1001',
            'call.dial_status' => 'ANSWER',
        ]))->assertOk();

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'occurred_at' => '2026-10-05T08:10:40Z',
            'asterisk.uniqueid' => '1791224971.52',
            'asterisk.linkedid' => '1791224971.52',
            'asterisk.channel' => 'PJSIP/1002-00000007',
            'call.caller_number' => '1002',
            'call.extension' => '1002',
            'call.dial_status' => '16',
        ]))->assertOk()->assertJsonPath('call.direction', 'outbound')->assertJsonPath('call.status', 'ended');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'occurred_at' => '2026-10-05T08:10:40Z',
            'asterisk.uniqueid' => '1791224971.54',
            'asterisk.linkedid' => '1791224971.52',
            'asterisk.channel' => 'PJSIP/1001-00000008',
            'call.caller_number' => '1001',
            'call.extension' => '1001',
            'call.dial_status' => '16',
        ]))->assertOk()->assertJsonPath('call.status', 'ended')->assertJsonPath('call.direction', 'inbound');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'occurred_at' => '2026-10-05T08:11:40Z',
            'asterisk.uniqueid' => '1791224971.54',
            'asterisk.linkedid' => '1791224971.52',
            'asterisk.channel' => 'PJSIP/1001-00000008',
            'call.caller_number' => '1001',
            'call.extension' => '1001',
            'call.dial_status' => '16',
        ]))->assertOk()->assertJsonPath('applied', false);

        $this->assertSame(2, Call::query()->count());
        $inbound = Call::query()->where('direction', CallDirection::Inbound)->firstOrFail();
        $outbound = Call::query()->where('direction', CallDirection::Outbound)->firstOrFail();
        $this->assertSame(CallStatus::Ended, $inbound->status);
        $this->assertSame(CallStatus::Ended, $outbound->status);
        $this->assertSame(30, $inbound->duration_seconds);
        $this->assertSame(30, $outbound->duration_seconds);
        $this->assertSame('1002', $inbound->caller_number_raw);
        $this->assertSame('1001', $inbound->extension_number);
    }

    public function test_terminal_dial_statuses_and_late_events_follow_the_state_machine(): void
    {
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertOk();
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'call.dial_status' => 'BUSY',
        ]))->assertOk()->assertJsonPath('call.status', 'busy');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.answered',
            'asterisk.linkedid' => '1728000000.9',
            'asterisk.uniqueid' => '1728000000.91',
        ]))->assertStatus(202);

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ringing',
            'asterisk.linkedid' => '1728000000.8',
            'asterisk.uniqueid' => '1728000000.81',
        ]))->assertOk();
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.answered',
            'asterisk.linkedid' => '1728000000.8',
            'asterisk.uniqueid' => '1728000000.81',
        ]))->assertOk();
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ringing',
            'asterisk.linkedid' => '1728000000.8',
            'asterisk.uniqueid' => '1728000000.81',
        ]))->assertOk()->assertJsonPath('applied', false)->assertJsonPath('call.status', 'answered');

        $this->assertSame(CallStatus::Busy, Call::query()->where('asterisk_linkedid', '1728000000.1')->firstOrFail()->status);
    }

    public function test_noanswer_and_congestion_map_to_missed_and_failed(): void
    {
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'asterisk.linkedid' => 'noanswer',
            'asterisk.uniqueid' => 'noanswer.2',
        ]))->assertOk();
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'asterisk.linkedid' => 'noanswer',
            'asterisk.uniqueid' => 'noanswer.2',
            'call.dial_status' => 'NOANSWER',
        ]))->assertJsonPath('call.status', 'missed');

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'asterisk.linkedid' => 'failed',
            'asterisk.uniqueid' => 'failed.2',
        ]))->assertOk();
        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'call.ended',
            'asterisk.linkedid' => 'failed',
            'asterisk.uniqueid' => 'failed.2',
            'call.dial_status' => 'CHANUNAVAIL',
        ]))->assertJsonPath('call.status', 'failed');

        $this->assertNull(Call::query()->where('status', 'missed')->value('duration_seconds'));
    }

    public function test_popup_history_contains_at_most_five_older_calls(): void
    {
        Event::fake([CallPopupUpdated::class]);

        foreach (range(1, 6) as $offset) {
            Call::factory()->create([
                'phone' => '09121234567',
                'caller_number_raw' => '09121234567',
                'caller_number_normalized' => '9121234567',
                'extension_number' => '1001',
                'status' => 'ended',
                'started_at' => now()->subDays($offset),
                'duration_seconds' => 10 + $offset,
            ]);
        }

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertOk();

        Event::assertDispatched(CallPopupUpdated::class, function (CallPopupUpdated $event): bool {
            $history = $event->call['history'];

            return count($history) === 5
                && collect($history)->pluck('id')->doesntContain($event->call['id']);
        });
    }

    public function test_invalid_payloads_return_422_without_logging_the_body(): void
    {
        $log = $this->captureLogs();

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', ['event_type' => 'call.ringing'])
            ->assertUnprocessable();

        $this->withToken($this->token)
            ->call('POST', '/api/internal/v1/asterisk/events', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ], '{')
            ->assertUnprocessable();

        $records = collect($log->getRecords())->map(fn ($record): string => $record['message'].' '.json_encode($record['context']));
        $this->assertTrue($records->contains(fn (string $line): bool => str_contains($line, 'asterisk.ingest.validation_failed')));
        $this->assertFalse($records->contains(fn (string $line): bool => str_contains($line, $this->token)));
    }

    public function test_the_ingest_route_is_rate_limited_per_token(): void
    {
        RateLimiter::for('asterisk-ingest', function (Request $request) {
            return Limit::perMinute(1)->by('asterisk-ingest-test');
        });

        $this->withToken($this->token)->postJson('/api/internal/v1/asterisk/events', $this->payload())->assertOk();

        $this->withToken($this->token)
            ->postJson('/api/internal/v1/asterisk/events', $this->payload([
                'event_id' => (string) Str::uuid(),
            ]))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_connector_token_command_prints_a_token_for_an_inactive_user(): void
    {
        $this->artisan('smartpbx:issue-connector-token')->assertSuccessful();

        $user = User::query()->where('email', 'connector@smartpbx.internal')->firstOrFail();

        $this->assertFalse($user->is_active);
        $this->assertSame(['asterisk:ingest'], $user->tokens()->firstOrFail()->abilities);
    }

    public function test_only_the_matching_operator_can_receive_a_popup(): void
    {
        $channel = new OperatorPopupChannel;
        $other = new User([
            'role' => UserRole::Operator,
            'is_active' => true,
        ]);
        $other->id = $this->operator->id + 1;
        $administrator = new User([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
        $administrator->id = $this->operator->id;

        $this->assertTrue($channel->join($this->operator, $this->operator->id));
        $this->assertFalse($channel->join($this->operator, $other->id));
        $this->assertFalse($channel->join($administrator, $administrator->id));
    }

    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);

        return $handler;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $payload = [
            'event_id' => '8f0c1d2e-7b3a-4e1f-9c2d-6a5b4c3d2e1f',
            'event_type' => 'call.ringing',
            'occurred_at' => '2026-10-05T08:10:00Z',
            'asterisk' => [
                'uniqueid' => '1728000000.2',
                'linkedid' => '1728000000.1',
                'channel' => 'SIP/1001-00000002',
            ],
            'call' => [
                'caller_number' => '09121234567',
                'caller_name' => '',
                'extension' => '1001',
                'dial_status' => 'RINGING',
            ],
        ];

        foreach ($overrides as $key => $value) {
            data_set($payload, $key, $value);
        }

        return $payload;
    }
}
