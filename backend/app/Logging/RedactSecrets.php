<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Removes credential-shaped values before a log record is written.
 */
class RedactSecrets
{
    /**
     * @var list<string>
     */
    private const KEYS = [
        'password',
        'secret',
        'token',
        'authorization',
        'api_token',
        'plaintexttoken',
        'ami_secret',
    ];

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: self::redactString($record->message),
                context: self::redact($record->context),
            );
        });
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSecretKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $child) {
                $redacted[$childKey] = self::redact($child, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    public static function redactString(string $value): string
    {
        $value = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/(password|secret|token|authorization)\s*[=:]\s*\S+/i', '$1=[redacted]', $value) ?? $value;

        return $value;
    }

    private static function isSecretKey(string $key): bool
    {
        return in_array(strtolower($key), self::KEYS, true);
    }
}
