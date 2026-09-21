<?php

use App\Http\Controllers\Api\V1\Sales\SaleController;
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
| GET  /sales/{sale}/receipt     the printable document (?width=58|80|a4)
|
| There is no PUT and no DELETE. A posted transaction is corrected by a
| cancellation, never by editing the row, so the receipt, the stock movement and
| the takings keep telling the same story. Checkout lives on the collection
| rather than on a cart route because the sale — not the draft — is the document
| being created, and its permissions belong to the sales family.
*/

Route::prefix('sales')->name('sales.')->group(function () {
    Route::get('/', [SaleController::class, 'index'])->name('index');
    Route::post('/', [SaleController::class, 'store'])->name('store');
    Route::get('{sale}', [SaleController::class, 'show'])->name('show');
    Route::post('{sale}/complete', [SaleController::class, 'complete'])->name('complete');
    Route::post('{sale}/cancel', [SaleController::class, 'cancel'])->name('cancel');
    Route::get('{sale}/receipt', [SaleController::class, 'receipt'])->name('receipt');
});
