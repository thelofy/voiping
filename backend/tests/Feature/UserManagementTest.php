<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_open_the_user_list(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertSee('Users');
    }

    public function test_operator_cannot_open_the_user_list(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->get('/users')
            ->assertForbidden();
    }

    public function test_administrator_can_create_an_inactive_operator(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post('/users', [
            'name' => 'Desk Operator',
            'email' => 'operator@example.com',
            'role' => UserRole::Operator->value,
            'password' => 'long-password-1',
            'password_confirmation' => 'long-password-1',
        ]);

        $response->assertRedirect(route('users.index'));

        $operator = User::query()->where('email', 'operator@example.com')->first();

        $this->assertNotNull($operator);
        $this->assertTrue($operator->isOperator());
        $this->assertFalse($operator->is_active);

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'operator@example.com',
            'password' => 'long-password-1',
        ]);

        $this->assertGuest();
    }

    public function test_short_password_is_rejected(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from('/users/create')
            ->post('/users', [
                'name' => 'Desk Operator',
                'email' => 'operator@example.com',
                'role' => UserRole::Operator->value,
                'is_active' => '1',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password')
            ->assertRedirect('/users/create');
    }

    public function test_last_active_administrator_cannot_be_demoted(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.edit', $admin))
            ->put(route('users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => UserRole::Operator->value,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->refresh()->isAdministrator());
    }

    public function test_last_active_administrator_cannot_be_deactivated(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::Administrator->value,
        ]);

        $this->assertTrue($admin->refresh()->is_active);
        $this->assertTrue($admin->isAdministrator());
    }

    public function test_another_administrator_can_be_demoted_when_one_remains(): void
    {
        $admin = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $second), [
            'name' => $second->name,
            'email' => $second->email,
            'role' => UserRole::Operator->value,
            'is_active' => '1',
        ])->assertRedirect(route('users.index'));

        $this->assertTrue($second->refresh()->isOperator());
    }

    public function test_operator_cannot_create_a_user(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->post('/users', [
            'name' => 'Other',
            'email' => 'other@example.com',
            'role' => UserRole::Operator->value,
            'is_active' => '1',
            'password' => 'long-password-1',
            'password_confirmation' => 'long-password-1',
        ])->assertForbidden();
    }
}
