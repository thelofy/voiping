<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    /**
     * @param  array{name: string, email: string, role: string, is_active: bool, password: string}  $attributes
     */
    public function create(array $attributes): User
    {
        return User::query()->create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'role' => $attributes['role'],
            'is_active' => $attributes['is_active'],
            'password' => $attributes['password'],
        ]);
    }

    /**
     * @param  array{name: string, email: string, role: string, is_active: bool, password?: string|null}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $nextRole = UserRole::from($attributes['role']);
            $nextActive = (bool) $attributes['is_active'];

            if ($this->removesLastActiveAdministrator($locked, $nextRole, $nextActive)) {
                throw ValidationException::withMessages([
                    'role' => 'The last active administrator cannot be deactivated or demoted.',
                ]);
            }

            $locked->fill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'role' => $nextRole,
                'is_active' => $nextActive,
            ]);

            if (! empty($attributes['password'])) {
                $locked->password = $attributes['password'];
            }

            $locked->save();

            return $locked;
        });
    }

    public function updateOwnName(User $user, string $name): void
    {
        $user->name = $name;
        $user->save();
    }

    public function changePassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->save();
    }

    private function removesLastActiveAdministrator(User $user, UserRole $nextRole, bool $nextActive): bool
    {
        if (! $user->isAdministrator() || ! $user->is_active) {
            return false;
        }

        if ($nextRole === UserRole::Administrator && $nextActive) {
            return false;
        }

        $remaining = User::query()
            ->where('role', UserRole::Administrator)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->lockForUpdate()
            ->count();

        return $remaining === 0;
    }
}
