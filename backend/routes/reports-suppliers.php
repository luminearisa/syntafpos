<?php

use App\Http\Controllers\Api\V1\Reports\SupplierReportController;

// Supplier reports (spec §34) are read-only aggregations over the purchasing
// documents, so they sit outside the apiResource shape under their own prefix.
Route::prefix('reports/suppliers')->group(function () {
    Route::get('summary', [SupplierReportController::class, 'summary'])->name('reports.suppliers.summary');
    // Declared after the fixed summary path so it cannot be swallowed by the
    // supplier parameter, and number-bound so letters never reach the lookup.
    Route::get('{supplier}/detail', [SupplierReportController::class, 'detail'])
        ->whereNumber('supplier')
        ->name('reports.suppliers.detail');
});
