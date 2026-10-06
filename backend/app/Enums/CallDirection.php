<?php

namespace App\Enums;

/**
 * Which way the call traveled relative to the PBX extension.
 */
enum CallDirection: string
{
    /** A call received by an extension. */
    case Inbound = 'inbound';

    /** A call placed from an extension. */
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Inbound => 'A call received by an extension.',
            self::Outbound => 'A call placed from an extension.',
        };
    }
}
