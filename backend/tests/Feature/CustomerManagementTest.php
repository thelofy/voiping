<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    public function test_inactive_user_cannot_open_customers(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)
            ->get(route('customers.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_operator_can_create_view_and_edit_a_customer(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->post(route('customers.store'), [
            'name' => 'Sara Ahmadi',
            'notes' => 'Prefers morning calls',
            'phones' => [
                ['raw_number' => '09121234567', 'label' => 'mobile'],
            ],
        ])->assertRedirect();

        $customer = Customer::query()->where('name', 'Sara Ahmadi')->first();

        $this->assertNotNull($customer);
        $this->assertDatabaseHas('customer_phones', [
            'customer_id' => $customer->id,
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
            'label' => 'mobile',
        ]);

        $this->actingAs($operator)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Sara Ahmadi')
            ->assertSee('Prefers morning calls')
            ->assertSee('09121234567')
            ->assertDontSee('Delete customer');

        $this->actingAs($operator)->put(route('customers.update', $customer), [
            'name' => 'Sara Ahmadi',
            'notes' => 'Afternoon only',
            'phones' => [
                ['raw_number' => '09121234567', 'label' => 'mobile'],
            ],
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertSame('Afternoon only', $customer->refresh()->notes);
    }

    public function test_duplicate_number_in_another_format_is_rejected(): void
    {
        $admin = User::factory()->create();
        $existing = Customer::factory()->create(['name' => 'Sara Ahmadi']);
        $existing->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
            'label' => 'mobile',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('customers.create'))
            ->post(route('customers.store'), [
                'name' => 'Other Person',
                'phones' => [
                    ['raw_number' => '+98 912 123 4567', 'label' => ''],
                ],
            ]);

        $response->assertRedirect(route('customers.create'));
        $response->assertSessionHasErrors('phones.0.raw_number');
        $this->assertTrue(collect(session('errors')->get('phones.0.raw_number'))
            ->contains(fn (string $message) => str_contains($message, 'Sara Ahmadi')));
        $this->assertDatabaseMissing('customers', ['name' => 'Other Person']);
    }

    public function test_invalid_short_and_long_notes_are_rejected(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('customers.create'))
            ->post(route('customers.store'), [
                'name' => 'Sara Ahmadi',
                'phones' => [
                    ['raw_number' => '1001'],
                ],
            ])
            ->assertRedirect(route('customers.create'))
            ->assertSessionHasErrors('phones.0.raw_number');

        $this->actingAs($admin)
            ->from(route('customers.create'))
            ->post(route('customers.store'), [
                'name' => 'Sara Ahmadi',
                'notes' => str_repeat('a', 501),
                'phones' => [
                    ['raw_number' => '09121234567'],
                ],
            ])
            ->assertRedirect(route('customers.create'))
            ->assertSessionHasErrors('notes');
    }

    public function test_name_and_phone_search_use_normalized_phone_numbers(): void
    {
        $admin = User::factory()->create();
        $sara = Customer::factory()->create(['name' => 'Sara Ahmadi']);
        $sara->phones()->create([
            'raw_number' => '+98 912 123 4567',
            'normalized_number' => '9121234567',
        ]);
        $other = Customer::factory()->create(['name' => 'Other Person']);
        $other->phones()->create([
            'raw_number' => '02188776655',
            'normalized_number' => '2188776655',
        ]);
        Customer::factory()->create(['name' => '100% Sara']);

        $this->actingAs($admin)
            ->get(route('customers.index', ['name' => 'Ahmadi']))
            ->assertOk()
            ->assertSee('Sara Ahmadi')
            ->assertDontSee('Other Person');

        $this->actingAs($admin)
            ->get(route('customers.index', ['phone' => '09121234567']))
            ->assertOk()
            ->assertSee('Sara Ahmadi')
            ->assertDontSee('Other Person');

        $this->actingAs($admin)
            ->get(route('customers.index', ['phone' => '1001']))
            ->assertOk()
            ->assertSee('That phone number cannot be searched')
            ->assertDontSee('Sara Ahmadi');

        $this->actingAs($admin)
            ->get(route('customers.index', ['name' => '%']))
            ->assertOk()
            ->assertSee('100% Sara')
            ->assertDontSee('Other Person')
            ->assertDontSee('Sara Ahmadi');
    }

    public function test_customer_list_is_paginated(): void
    {
        $admin = User::factory()->create();

        foreach (range(1, 26) as $number) {
            Customer::factory()->create(['name' => sprintf('Customer %02d', $number)]);
        }

        $this->actingAs($admin)
            ->get(route('customers.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Customer 26')
            ->assertDontSee('Customer 01');
    }

    public function test_notes_are_escaped(): void
    {
        $admin = User::factory()->create();
        $customer = Customer::factory()->create([
            'name' => 'Sara Ahmadi',
            'notes' => '<script>alert(1)</script>',
        ]);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->actingAs($admin)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_operator_cannot_delete_a_customer(): void
    {
        $operator = User::factory()->operator()->create();
        $customer = Customer::factory()->create();
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->actingAs($operator)
            ->delete(route('customers.destroy', $customer), ['confirmed' => '1'])
            ->assertForbidden();

        $this->assertModelExists($customer);
    }

    public function test_delete_requires_confirmation_and_keeps_the_call(): void
    {
        $admin = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Sara Ahmadi']);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);
        $call = Call::factory()->create([
            'customer_id' => $customer->id,
            'phone' => '09121234567',
        ]);

        $this->actingAs($admin)
            ->from(route('customers.show', $customer))
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHasErrors('confirmed');

        $this->assertModelExists($customer);
        $this->assertSame($customer->id, $call->refresh()->customer_id);

        $this->actingAs($admin)
            ->delete(route('customers.destroy', $customer), ['confirmed' => '1'])
            ->assertRedirect(route('customers.index'));

        $this->assertModelMissing($customer);
        $this->assertDatabaseMissing('customer_phones', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('calls', [
            'id' => $call->id,
            'customer_id' => null,
            'phone' => '09121234567',
        ]);
    }

    public function test_customer_can_keep_their_own_number_when_editing(): void
    {
        $admin = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Sara Ahmadi']);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->actingAs($admin)->put(route('customers.update', $customer), [
            'name' => 'Sara Updated',
            'phones' => [
                ['raw_number' => '+98 912 123 4567', 'label' => 'mobile'],
                ['raw_number' => '02188776655', 'label' => 'office'],
            ],
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertSame('Sara Updated', $customer->refresh()->name);
        $this->assertEqualsCanonicalizing(
            ['9121234567', '2188776655'],
            $customer->phones()->pluck('normalized_number')->all(),
        );
    }

    public function test_operator_can_deactivate_and_activate_a_customer(): void
    {
        $operator = User::factory()->operator()->create();
        $customer = Customer::factory()->create([
            'name' => 'Sara Ahmadi',
            'company_name' => 'North Office',
            'email' => 'sara@example.com',
        ]);
        $customer->phones()->create([
            'raw_number' => '09121234567',
            'normalized_number' => '9121234567',
        ]);

        $this->actingAs($operator)
            ->patch(route('customers.active', $customer), ['is_active' => '0'])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertFalse($customer->refresh()->is_active);

        $this->actingAs($operator)
            ->get(route('customers.index', ['name' => 'Sara']))
            ->assertOk()
            ->assertSee('Inactive')
            ->assertSee('Activate');

        $this->actingAs($operator)
            ->patch(route('customers.active', $customer), ['is_active' => '1'])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertTrue($customer->refresh()->is_active);
        $this->assertSame('North Office', $customer->company_name);
        $this->assertSame('sara@example.com', $customer->email);
    }

    public function test_customer_without_a_phone_is_rejected(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->from(route('customers.create'))
            ->post(route('customers.store'), [
                'name' => 'No Phone',
                'phones' => [
                    ['raw_number' => ''],
                ],
            ])
            ->assertRedirect(route('customers.create'))
            ->assertSessionHasErrors('phones');

        $this->assertDatabaseMissing('customers', ['name' => 'No Phone']);
    }
}
