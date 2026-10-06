<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Operator => 'Operator',
        };
    }
}
