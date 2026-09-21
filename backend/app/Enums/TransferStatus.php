<?php

namespace App\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
