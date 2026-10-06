<?php

namespace Tests\Unit;

use App\Logging\RedactSecrets;
use PHPUnit\Framework\TestCase;

class RedactSecretsTest extends TestCase
{
    public function test_it_removes_credentials_from_log_context_and_messages(): void
    {
        $redacted = RedactSecrets::redact([
            'authorization' => 'Bearer secret-token',
            'event_id' => '8f0c1d2e-7b3a-4e1f-9c2d-6a5b4c3d2e1f',
            'nested' => ['password' => 'hunter2'],
        ]);

        $this->assertSame('[redacted]', $redacted['authorization']);
        $this->assertSame('[redacted]', $redacted['nested']['password']);
        $this->assertSame('8f0c1d2e-7b3a-4e1f-9c2d-6a5b4c3d2e1f', $redacted['event_id']);
        $this->assertSame(
            'login failed token=[redacted]',
            RedactSecrets::redactString('login failed token=super-secret'),
        );
        $this->assertStringNotContainsString(
            'secret-token',
            RedactSecrets::redactString('Authorization: Bearer secret-token'),
        );
    }
}
