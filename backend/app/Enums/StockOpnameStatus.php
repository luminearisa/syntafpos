<?php

namespace App\Enums;

enum StockOpnameStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case Review = 'review';
    case Approved = 'approved';
    case Posted = 'posted';
}
