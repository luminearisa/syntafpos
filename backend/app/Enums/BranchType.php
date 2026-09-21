<?php

namespace App\Enums;

enum BranchType: string
{
    case HeadOffice = 'head_office';
    case Outlet = 'outlet';
    case Warehouse = 'warehouse';
    case Other = 'other';
}
