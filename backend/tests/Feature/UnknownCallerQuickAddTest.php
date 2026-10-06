<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnknownCallerQuickAddTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_saves_an_unknown_caller_onto_the_active_call(): void
    {
        $operator = User::factory()->operator()->create();
        $call = Call::factory()->create([
            'user_id' => $operator->id,
            'phone' => '09121234567',
            'caller_number_raw' => '09121234567',
            'caller_number_normalized' => '9121234567',
            'asterisk_linkedid' => '1791227125.195',
            'status' => CallStatus::Ringing,
            'customer_id' => null,
        ]);

        $this->actingAs($operator)
            ->postJson(route('api.customers.quick-add'), [
                'name' => 'Sara Ahmadi',
                'company_name' => 'North Office',
                'notes' => 'Asked for a callback',
                'phone' => '09121234567',
                'linkedid' => '1791227125.195',
            ])
            ->assertCreated()
            ->assertJsonPath('customer.name', 'Sara Ahmadi')
            ->assertJsonPath('customer.company_name', 'North Office');

        $customer = Customer::query()->where('name', 'Sara Ahmadi')->firstOrFail();

        $this->assertSame($customer->id, $call->fresh()->customer_id);
        $this->assertDatabaseHas('customer_phones', [
            'customer_id' => $customer->id,
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);
    }

    public function test_a_three_digit_extension_can_be_saved_as_the_caller(): void
    {
        $operator = User::factory()->operator()->create();
        $call = Call::factory()->create([
            'user_id' => $operator->id,
            'phone' => '101',
            'caller_number_raw' => '101',
            'caller_number_normalized' => '',
            'asterisk_linkedid' => '1791227125.195',
            'status' => CallStatus::Ringing,
            'customer_id' => null,
        ]);

        $this->actingAs($operator)
            ->postJson(route('api.customers.quick-add'), [
                'name' => 'Test 101',
                'phone' => '101',
                'linkedid' => '1791227125.195',
            ])
            ->assertCreated();

        $this->assertNotNull($call->fresh()->customer_id);
        $this->assertDatabaseHas('customer_phones', [
            'raw_number' => '101',
            'normalized_number' => '101',
        ]);
    }

    public function test_a_different_operator_cannot_link_the_call(): void
    {
        $owner = User::factory()->operator()->create();
        $other = User::factory()->operator()->create();
        Call::factory()->create([
            'user_id' => $owner->id,
            'phone' => '09121234567',
            'caller_number_raw' => '09121234567',
            'caller_number_normalized' => '9121234567',
            'asterisk_linkedid' => '1791227125.195',
            'status' => CallStatus::Answered,
            'customer_id' => null,
        ]);

        $this->actingAs($other)
            ->postJson(route('api.customers.quick-add'), [
                'name' => 'Sara Ahmadi',
                'phone' => '09121234567',
                'linkedid' => '1791227125.195',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linkedid');

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_operator_layout_includes_the_unknown_caller_form(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Save customer', false)
            ->assertSee('readonly', false);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Save customer', false);
    }

    public function test_a_finished_call_is_not_linked(): void
    {
        $operator = User::factory()->operator()->create();
        $call = Call::factory()->create([
            'user_id' => $operator->id,
            'phone' => '09121234567',
            'caller_number_raw' => '09121234567',
            'caller_number_normalized' => '9121234567',
            'asterisk_linkedid' => '1791227125.195',
            'status' => CallStatus::Ended,
            'customer_id' => null,
        ]);

        $this->actingAs($operator)
            ->postJson(route('api.customers.quick-add'), [
                'name' => 'Sara Ahmadi',
                'phone' => '09121234567',
                'linkedid' => '1791227125.195',
            ])
            ->assertUnprocessable();

        $this->assertNull($call->fresh()->customer_id);
        $this->assertSame(0, CustomerPhone::query()->count());
    }
}
