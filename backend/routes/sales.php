<?php

use App\Http\Controllers\Api\V1\Refunds\RefundController;
use App\Http\Controllers\Api\V1\Sales\SaleController;
use App\Http\Controllers\Api\V1\Sales\SaleReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales (Phase 3.2)
|--------------------------------------------------------------------------
|
| POST /sales                    checkout a cart: sale + stock out + payment
| GET  /sales                    the transaction list, filterable
| GET  /sales/{sale}             one sale — the invoice data
| POST /sales/{sale}/complete    take further payment, then post and close
| POST /sales/{sale}/cancel      withdraw it, returning stock through the ledger
| POST /sales/{sale}/void        withdraw an open ticket, with a reason (3.5)
| GET  /sales/{sale}/receipt     the printable document (?width=58|80|a4)
|
| Phase 3.5 adds the two documents that reverse a completed sale, both raised
| against the sale itself: a return (goods in) and a refund (money out). They are
| nested here because a return without the sale it points at is not a return.
|
| There is no PUT and no DELETE. A posted transaction is corrected by a
| cancellation or a return, never by editing the row, so the receipt, the stock
| movement and the takings keep telling the same story. Checkout lives on the
| collection rather than on a cart route because the sale — not the draft — is
| the document being created, and its permissions belong to the sales family.
*/

Route::prefix('sales')->name('sales.')->group(function () {
    Route::get('/', [SaleController::class, 'index'])->name('index');
    Route::post('/', [SaleController::class, 'store'])->name('store');
    Route::get('{sale}', [SaleController::class, 'show'])->name('show');
    Route::post('{sale}/complete', [SaleController::class, 'complete'])->name('complete');
    Route::post('{sale}/cancel', [SaleController::class, 'cancel'])->name('cancel');
    Route::post('{sale}/void', [SaleController::class, 'void'])->name('void');
    Route::get('{sale}/receipt', [SaleController::class, 'receipt'])->name('receipt');

    // Goods back against this sale, and the money given back for it.
    Route::get('{sale}/returns', [SaleReturnController::class, 'indexForSale'])->name('returns.index');
    Route::post('{sale}/returns', [SaleReturnController::class, 'store'])->name('returns.store');
    Route::get('{sale}/refunds', [RefundController::class, 'indexForSale'])->name('refunds.index');
    Route::post('{sale}/refunds', [RefundController::class, 'store'])->name('refunds.store');
});
