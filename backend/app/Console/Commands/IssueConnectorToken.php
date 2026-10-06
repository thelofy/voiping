<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueConnectorToken extends Command
{
    protected $signature = 'smartpbx:issue-connector-token';

    protected $description = 'Create a Sanctum token with the asterisk:ingest ability and print it once';

    public function handle(): int
    {
        $ability = (string) config('smartpbx.asterisk_ingest_ability', 'asterisk:ingest');

        $user = User::query()->firstOrCreate(
            ['email' => 'connector@smartpbx.internal'],
            [
                'name' => 'Asterisk Connector',
                'password' => Str::password(40),
                'role' => UserRole::Operator,
                'is_active' => false,
            ],
        );

        if ($user->is_active) {
            $user->forceFill(['is_active' => false])->save();
        }

        $token = $user->createToken('asterisk-connector', [$ability]);

        $this->info('Copy this token into the connector environment. It will not be shown again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
