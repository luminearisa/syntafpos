<?php

namespace App\Enums;

enum StockAdjustmentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
}
