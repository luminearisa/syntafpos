<?php

namespace App\Enums;

enum EntityStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Draft = 'draft';
    case Closed = 'closed';
}
