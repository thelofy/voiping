<?php

namespace Tests\Unit;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Customer;
use App\Models\User;
use App\Services\CallLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CallLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_inbound_and_outbound_calls(): void
    {
        $service = app(CallLogService::class);

        $inbound = $service->record($this->payload());
        $outbound = $service->record($this->payload([
            'direction' => 'outbound',
            'status' => 'missed',
            'ended_at' => '2026-10-05 08:00:20',
            'phone' => '02188776655',
        ]));

        $this->assertSame(CallDirection::Inbound, $inbound->direction);
        $this->assertSame(CallStatus::Ringing, $inbound->status);
        $this->assertNull($inbound->duration);
        $this->assertNull($inbound->unique_id);

        $this->assertSame(CallDirection::Outbound, $outbound->direction);
        $this->assertSame(CallStatus::Missed, $outbound->status);
        $this->assertNull($outbound->duration);
    }

    public function test_ended_calls_store_duration_from_answer_to_end(): void
    {
        $call = app(CallLogService::class)->record($this->payload([
            'status' => 'ended',
            'answered_at' => '2026-10-05 08:00:10',
            'ended_at' => '2026-10-05 08:02:10',
            'duration' => 1,
        ]));

        $this->assertSame(120, $call->duration);
        $this->assertSame('2:00', $call->formattedDuration());
    }

    public function test_unique_call_identifier_is_optional_and_unique(): void
    {
        $service = app(CallLogService::class);

        $first = $service->record($this->payload(['unique_id' => '']));
        $second = $service->record($this->payload(['unique_id' => null, 'phone' => '09120000000']));
        $identified = $service->record($this->payload([
            'unique_id' => '1750000000.12',
            'phone' => '09121111111',
        ]));

        $this->assertNull($first->unique_id);
        $this->assertNull($second->unique_id);
        $this->assertSame('1750000000.12', $identified->unique_id);

        try {
            $service->record($this->payload([
                'unique_id' => '1750000000.12',
                'phone' => '09122222222',
            ]));
            $this->fail('A repeated call identifier was stored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unique_id', $exception->errors());
        }
    }

    public function test_times_must_match_the_status(): void
    {
        try {
            app(CallLogService::class)->record($this->payload([
                'status' => 'answered',
                'answered_at' => '2026-10-05 07:59:00',
            ]));
            $this->fail('An answer before the start was stored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answered_at', $exception->errors());
        }

        try {
            app(CallLogService::class)->record($this->payload([
                'status' => 'missed',
                'answered_at' => '2026-10-05 08:00:05',
                'ended_at' => '2026-10-05 08:00:20',
            ]));
            $this->fail('A missed call with an answer time was stored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    public function test_call_belongs_to_a_customer_and_user_and_survives_customer_deletion(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sara Ahmadi']);
        $user = User::factory()->operator()->create();

        $call = app(CallLogService::class)->record($this->payload([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
        ]));

        $this->assertTrue($customer->calls()->whereKey($call->id)->exists());
        $this->assertTrue($user->calls()->whereKey($call->id)->exists());
        $this->assertSame('Sara Ahmadi', $call->customer?->name);

        $customer->delete();

        $this->assertNull($call->fresh()->customer_id);
        $this->assertNotNull($call->fresh());
    }

    public function test_lists_are_scoped_to_the_viewer_and_can_be_filtered(): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->operator()->create();
        $other = User::factory()->operator()->create();
        $service = app(CallLogService::class);

        $service->record($this->payload([
            'user_id' => $operator->id,
            'phone' => '09121234567',
            'extension' => '1001',
            'direction' => 'inbound',
            'started_at' => '2026-01-02 09:00:00',
        ]));
        $service->record($this->payload([
            'user_id' => $other->id,
            'phone' => '09350000000',
            'extension' => '1002',
            'direction' => 'outbound',
            'status' => 'busy',
            'ended_at' => '2026-02-02 09:05:00',
            'started_at' => '2026-02-02 09:00:00',
        ]));

        $own = $service->paginate(['phone' => '0912'], $operator);
        $this->assertCount(1, $own);
        $this->assertSame('09121234567', $own->items()[0]->phone);

        $adminRows = $service->paginate([
            'direction' => 'outbound',
            'extension' => '1002',
            'status' => 'busy',
            'started_from' => '2026-02-01',
            'started_to' => '2026-02-03',
        ], $admin);

        $this->assertCount(1, $adminRows);
        $this->assertSame($other->id, $adminRows->items()[0]->user_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'phone' => '09121234567',
            'extension' => '1001',
            'direction' => 'inbound',
            'status' => 'ringing',
            'started_at' => '2026-10-05 08:00:00',
        ], $overrides);
    }
}
