<?php

namespace App\Enums;

enum BarcodeType: string
{
    case Ean = 'ean';
    case Upc = 'upc';
    case Code128 = 'code128';
    case Qr = 'qr';
    case Internal = 'internal';
}
