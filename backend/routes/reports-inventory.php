<?php

use App\Http\Controllers\Api\V1\Reports\InventoryReportController;

/**
 * Inventory reports (spec §32): read-only aggregations over the stock ledger.
 * Each endpoint is guarded by the reports.inventory permission gate directly,
 * because no single model is owned here.
 */
Route::prefix('reports/inventory')->group(function () {
    Route::get('stock-summary', [InventoryReportController::class, 'stockSummary'])
        ->name('reports.inventory.stock-summary');

    Route::get('stock-card', [InventoryReportController::class, 'stockCard'])
        ->name('reports.inventory.stock-card');

    Route::get('stock-movements', [InventoryReportController::class, 'stockMovements'])
        ->name('reports.inventory.stock-movements');

    Route::get('low-stock', [InventoryReportController::class, 'lowStock'])
        ->name('reports.inventory.low-stock');

    Route::get('stock-valuation', [InventoryReportController::class, 'stockValuation'])
        ->name('reports.inventory.stock-valuation');

    Route::get('stock-opnames', [InventoryReportController::class, 'stockOpnames'])
        ->name('reports.inventory.stock-opnames');

    Route::get('warehouse-transfers', [InventoryReportController::class, 'warehouseTransfers'])
        ->name('reports.inventory.warehouse-transfers');
});
