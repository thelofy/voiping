<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the local administrator.
     *
     * Credentials come from the environment and are never written into this file.
     * Running the seeder again resets that administrator's password.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $email = env('SEED_ADMIN_EMAIL');
        $password = env('SEED_ADMIN_PASSWORD');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SEED_ADMIN_NAME', 'SmartPBX Administrator'),
                'password' => $password,
                'role' => UserRole::Administrator,
                'is_active' => true,
            ],
        );
    }
}
