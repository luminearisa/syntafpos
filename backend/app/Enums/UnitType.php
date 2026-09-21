<?php

namespace App\Enums;

enum UnitType: string
{
    case Quantity = 'quantity';
    case Length = 'length';
    case Weight = 'weight';
    case Volume = 'volume';
    case Area = 'area';
}
