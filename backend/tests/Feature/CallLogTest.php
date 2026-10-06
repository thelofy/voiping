<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_call_log(): void
    {
        $this->get('/calls')->assertRedirect(route('login'));
    }

    public function test_guests_cannot_use_the_call_log_api(): void
    {
        $this->getJson('/api/calls')->assertUnauthorized();
        $this->postJson('/api/calls', [])->assertUnauthorized();
    }

    public function test_administrator_can_record_a_call_and_operators_cannot(): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->operator()->create();
        $customer = Customer::factory()->create(['name' => 'Sara Ahmadi']);

        $this->actingAs($operator)->postJson('/api/calls', $this->endedPayload($customer, $operator))
            ->assertForbidden();

        $this->actingAs($admin)->postJson('/api/calls', $this->endedPayload($customer, $operator))
            ->assertCreated()
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.duration', 90)
            ->assertJsonPath('data.unique_id', '1750000000.12')
            ->assertJsonPath('data.customer.name', 'Sara Ahmadi')
            ->assertJsonPath('data.user.id', $operator->id);

        $this->assertDatabaseHas('calls', [
            'phone' => '+98 912 123 4567',
            'duration' => 90,
            'unique_id' => '1750000000.12',
        ]);
    }

    public function test_history_is_scoped_filtered_and_paginated(): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->operator()->create();
        $other = User::factory()->operator()->create();

        Call::factory()->create([
            'user_id' => $operator->id,
            'phone' => '09121234567',
            'extension' => '1001',
            'direction' => 'inbound',
            'status' => 'ringing',
            'started_at' => '2026-03-01 10:00:00',
        ]);
        Call::factory()->ended()->create([
            'user_id' => $other->id,
            'phone' => '09351234567',
            'extension' => '1002',
            'direction' => 'outbound',
            'started_at' => '2026-03-02 10:00:00',
        ]);

        $this->actingAs($operator)->get('/calls')
            ->assertOk()
            ->assertSee('09121234567')
            ->assertDontSee('09351234567');

        $this->actingAs($operator)->getJson('/api/calls')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.phone', '09121234567');

        $this->actingAs($admin)->get('/calls?direction=outbound&extension=1002&status=ended&phone=0935&started_from=2026-03-01&started_to=2026-03-03')
            ->assertOk()
            ->assertSee('09351234567')
            ->assertSee('1:30')
            ->assertDontSee('09121234567');

        $this->actingAs($admin)->getJson('/api/calls?direction=inbound')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.extension', '1001');

        foreach (range(1, 26) as $i) {
            Call::factory()->create([
                'user_id' => $admin->id,
                'phone' => sprintf('0912000%04d', $i),
                'started_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($admin)->get('/calls?page=2')
            ->assertOk()
            ->assertSee('09120000026')
            ->assertDontSee('09120000001');

        $this->actingAs($admin)->getJson('/api/calls?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_invalid_filters_and_payloads_are_rejected(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->from('/calls')->get('/calls?status=nope')
            ->assertRedirect('/calls')
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)->getJson('/api/calls?per_page=101')
            ->assertUnprocessable();

        $this->actingAs($admin)->postJson('/api/calls', [
            'phone' => 'not-a-phone',
            'direction' => 'sideways',
            'status' => 'ended',
            'started_at' => '2026-10-05 08:00:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'direction']);
    }

    public function test_operators_can_view_only_their_own_call(): void
    {
        $operator = User::factory()->operator()->create();
        $other = User::factory()->operator()->create();
        $own = Call::factory()->create(['user_id' => $operator->id]);
        $theirs = Call::factory()->create(['user_id' => $other->id]);

        $this->assertTrue($operator->can('view', $own));
        $this->assertFalse($operator->can('view', $theirs));
        $this->assertTrue($other->can('viewAny', Call::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function endedPayload(Customer $customer, User $operator): array
    {
        return [
            'customer_id' => $customer->id,
            'user_id' => $operator->id,
            'phone' => '+98 912 123 4567',
            'extension' => '1001',
            'direction' => 'outbound',
            'status' => 'ended',
            'unique_id' => '1750000000.12',
            'started_at' => '2026-10-05 08:00:00',
            'answered_at' => '2026-10-05 08:00:05',
            'ended_at' => '2026-10-05 08:01:35',
        ];
    }
}
