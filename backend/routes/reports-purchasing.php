<?php

use App\Http\Controllers\Api\V1\Reports\PurchasingReportController;

Route::prefix('reports/purchasing')->group(function () {
    Route::get('summary', [PurchasingReportController::class, 'summary'])->name('reports.purchasing.summary');
    Route::get('detail', [PurchasingReportController::class, 'detail'])->name('reports.purchasing.detail');
    Route::get('by-supplier', [PurchasingReportController::class, 'bySupplier'])->name('reports.purchasing.by-supplier');
    Route::get('by-product', [PurchasingReportController::class, 'byProduct'])->name('reports.purchasing.by-product');
    Route::get('by-branch', [PurchasingReportController::class, 'byBranch'])->name('reports.purchasing.by-branch');
    Route::get('by-warehouse', [PurchasingReportController::class, 'byWarehouse'])->name('reports.purchasing.by-warehouse');
    Route::get('returns', [PurchasingReportController::class, 'returns'])->name('reports.purchasing.returns');
    Route::get('outstanding', [PurchasingReportController::class, 'outstanding'])->name('reports.purchasing.outstanding');
});
