<?php

namespace App\Enums;

enum PriceType: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Member = 'member';
    case Reseller = 'reseller';
}
