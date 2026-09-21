<?php

namespace App\Enums;

enum WarehouseType: string
{
    case Main = 'main';
    case Outlet = 'outlet';
    case Production = 'production';
    case Transit = 'transit';
}
