<?php

namespace App\Enums;

enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
    case Service = 'service';
    case Bundle = 'bundle';
    case RawMaterial = 'raw_material';
    case FinishedGood = 'finished_good';
    case Consumable = 'consumable';
}
