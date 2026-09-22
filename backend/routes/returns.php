<?php

use App\Http\Controllers\Api\V1\Refunds\RefundController;
use App\Http\Controllers\Api\V1\Sales\SaleReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Returns and refunds (Phase 3.5)
|--------------------------------------------------------------------------
|
| GET  /returns                        the returns list, filterable
| GET  /returns/{saleReturn}           one return slip, its lines and its refunds
| GET  /refunds                        the refunds list, filterable
| GET  /refunds/{refund}               one refund and the payments it came off
| POST /refunds/{refund}/approve       sign off one the threshold referred
| POST /refunds/{refund}/reject        refuse one before anything moved
| POST /refunds/{refund}/process       hand it to the payout
| POST /refunds/{refund}/complete      pay it out, writing the tenders down
| POST /refunds/{refund}/fail          record a payout that could not happen
|
| Creating a return or a refund is nested under its sale (see routes/sales.php),
| because both point at a sale that must exist. The transitions live here rather
| than on a PUT: each is a decision with a name on it, and a refund is never
| edited — a wrong one is failed and raised again.
*/

Route::prefix('returns')->name('returns.')->group(function () {
    Route::get('/', [SaleReturnController::class, 'index'])->name('index');
    Route::get('{saleReturn}', [SaleReturnController::class, 'show'])->name('show');
});

Route::prefix('refunds')->name('refunds.')->group(function () {
    Route::get('/', [RefundController::class, 'index'])->name('index');
    Route::get('{refund}', [RefundController::class, 'show'])->name('show');
    Route::post('{refund}/approve', [RefundController::class, 'approve'])->name('approve');
    Route::post('{refund}/reject', [RefundController::class, 'reject'])->name('reject');
    Route::post('{refund}/process', [RefundController::class, 'process'])->name('process');
    Route::post('{refund}/complete', [RefundController::class, 'complete'])->name('complete');
    Route::post('{refund}/fail', [RefundController::class, 'fail'])->name('fail');
});
