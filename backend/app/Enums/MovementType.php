<?php

namespace App\Enums;

enum MovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case ProductionIn = 'production_in';
    case ProductionOut = 'production_out';
    case Consumption = 'consumption';
    case StockOpname = 'stock_opname';
}
