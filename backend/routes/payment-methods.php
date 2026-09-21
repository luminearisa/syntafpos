<?php

use App\Http\Controllers\Api\V1\PaymentMethods\PaymentMethodController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment methods (Phase 3.3)
|--------------------------------------------------------------------------
|
| GET    /payment-methods            the configuration list, filterable
| GET    /payment-methods/available  what the till may offer right now
| POST   /payment-methods            add a way to be paid
| GET    /payment-methods/{method}   read one, with how much has used it
| PUT    /payment-methods/{method}   rename, retune, activate, deactivate
| DELETE /payment-methods/{method}   retire one — only while nothing used it
|
| `available` is declared ahead of the resource for the same reason products/
| lookup is: inside it the word would be swallowed as a {payment_method} id.
|
| The till reads only `available`; the shape it needs there is different enough
| from the admin list — unconfigured shops fall back to the catalogue defaults —
| that folding the two together would leave a cashier staring at an empty row of
| buttons until an owner finds this screen.
*/

Route::prefix('payment-methods')->name('payment-methods.')->group(function () {
    Route::get('available', [PaymentMethodController::class, 'available'])->name('available');

    Route::get('/', [PaymentMethodController::class, 'index'])->name('index');
    Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
    Route::get('{payment_method}', [PaymentMethodController::class, 'show'])->name('show');
    Route::put('{payment_method}', [PaymentMethodController::class, 'update'])->name('update');
    Route::delete('{payment_method}', [PaymentMethodController::class, 'destroy'])->name('destroy');
});
