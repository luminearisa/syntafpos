<?php

namespace App\Enums;

enum TaxType: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
