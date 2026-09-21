<?php

namespace App\Enums;

enum AdjustmentReason: string
{
    case Damage = 'damage';
    case Lost = 'lost';
    case Found = 'found';
    case Expired = 'expired';
    case CountingError = 'counting_error';
    case Other = 'other';
}
