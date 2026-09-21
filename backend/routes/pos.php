<?php

use App\Http\Controllers\Api\V1\Pos\PosCartController;
use App\Http\Controllers\Api\V1\Pos\PosProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Point of sale (Phase 3.1)
|--------------------------------------------------------------------------
|
| The till's product finder, then the cart lifecycle.
|
| GET  /pos/cart            open (or resume) the cashier's working cart
| POST /pos/cart            same, for clients that prefer an explicit create
| GET  /pos/cart/held       the parked-cart queue for recall
| POST /pos/cart/recall     bring a parked cart back by its recall code
| GET  /pos/cart/{id}       read one cart
| PUT  /pos/cart/{id}       customer, note, label, cart discount
| DELETE /pos/cart/{id}     discard a parked draft
| POST /pos/cart/{id}/items add a product or a scanned code
| PUT  /pos/cart/{id}/items/{item}      quantity, discount, note
| DELETE /pos/cart/{id}/items/{item}    remove one line
| DELETE /pos/cart/{id}/items           clear the cart
| POST /pos/cart/{id}/hold              park the cart
|
| The two collection-shaped routes are declared before {cart}: inside a resource
| they would be swallowed by the {id} parameter, the same way products/lookup
| sits ahead of the product resource.
|
| Nothing here posts stock, revenue or a journal: a cart is a draft until
| checkout consumes it in Subphase 3.2.
*/

Route::prefix('pos')->name('pos.')->group(function () {
    // One box for name, SKU, barcode, category and brand. A scan sends
    // ?barcode= and gets one exact answer rather than a grid.
    Route::get('products/search', [PosProductController::class, 'search'])->name('products.search');

    Route::prefix('cart')->name('cart.')->group(function () {
        Route::get('/', [PosCartController::class, 'current'])->name('current');
        Route::post('/', [PosCartController::class, 'store'])->name('store');

        // Parked drafts are read and recalled by code, before any {cart} binding.
        Route::get('held', [PosCartController::class, 'held'])->name('held');
        Route::post('recall', [PosCartController::class, 'recall'])->name('recall');

        Route::prefix('{cart}')->group(function () {
            Route::get('/', [PosCartController::class, 'show'])->name('show');
            Route::put('/', [PosCartController::class, 'update'])->name('update');
            Route::delete('/', [PosCartController::class, 'destroy'])->name('destroy');

            Route::post('items', [PosCartController::class, 'addItem'])->name('items.store');
            Route::delete('items', [PosCartController::class, 'clear'])->name('items.clear');
            Route::put('items/{item}', [PosCartController::class, 'updateItem'])->name('items.update');
            Route::delete('items/{item}', [PosCartController::class, 'removeItem'])->name('items.destroy');

            Route::post('hold', [PosCartController::class, 'hold'])->name('hold');
        });
    });
});
