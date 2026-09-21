<?php

namespace App\Enums;

enum PurchaseReturnStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
