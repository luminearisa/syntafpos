<?php

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Held = 'held';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Held => 'On hold',
        };
    }
}
