<?php

namespace App\Enums;

enum LocationType: string
{
    case Zone = 'zone';
    case Rack = 'rack';
    case Bin = 'bin';
    case Area = 'area';
}
