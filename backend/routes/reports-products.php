<?php

use App\Http\Controllers\Api\V1\Reports\ProductAnalyticsController;

// Product analytics (spec §35) is a read-only aggregation across stock,
// pricing and purchasing, so it sits outside the apiResource shape.
Route::prefix('reports/products')->group(function () {
    Route::get('analytics', [ProductAnalyticsController::class, 'index'])->name('reports.products.analytics');
});
