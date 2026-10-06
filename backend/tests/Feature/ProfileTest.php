<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_display_name_can_be_updated(): void
    {
        $user = User::factory()->operator()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Updated Name',
                'email' => 'changed@example.com',
                'role' => 'administrator',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Updated Name', $user->name);
        $this->assertNotSame('changed@example.com', $user->email);
        $this->assertTrue($user->isOperator());
    }

    public function test_user_cannot_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile')
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }
}
