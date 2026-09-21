<?php

namespace App\Enums;

enum SequenceResetPeriod: string
{
    case Never = 'never';
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
