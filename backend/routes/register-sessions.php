<?php

use App\Http\Controllers\Api\V1\RegisterSessions\RegisterSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Register sessions (Phase 3.4)
|--------------------------------------------------------------------------
|
| GET  /register-sessions                      shift history, filterable
| GET  /register-sessions/registers            drawers, with the one now open
| GET  /register-sessions/current              the shift this till is working
| POST /register-sessions                      open a register with float
| GET  /register-sessions/{session}            one shift + its live figures
| POST /register-sessions/{session}/close      count the drawer and shut it
| POST /register-sessions/{session}/approve    sign off a variance
| POST /register-sessions/{session}/reopen     put a closed shift back open
| GET  /register-sessions/{session}/report     the closing report
| GET  /register-sessions/{session}/movements  cash in / cash out on it
| POST /register-sessions/{session}/movements  record one
|
| No PUT, no DELETE. A counted shift is corrected by reopening it and counting
| again, never by editing the figures — which is the whole reason a variance means
| anything: if the numbers could be typed over, a shortage could be made to
| disappear without a supervisor noticing.
|
| `registers` and `current` are declared ahead of the {registerSession} binding, the
| same way the cart's collection routes sit ahead of {cart}: a word there would
| otherwise be read as an id and 404 as a missing shift.
*/

Route::prefix('register-sessions')->name('register-sessions.')->group(function () {
    Route::get('/', [RegisterSessionController::class, 'index'])->name('index');
    Route::post('/', [RegisterSessionController::class, 'open'])->name('open');

    Route::get('registers', [RegisterSessionController::class, 'registers'])->name('registers');
    Route::get('current', [RegisterSessionController::class, 'current'])->name('current');

    Route::prefix('{registerSession}')->group(function () {
        Route::get('/', [RegisterSessionController::class, 'show'])->name('show');
        Route::post('close', [RegisterSessionController::class, 'close'])->name('close');
        Route::post('approve', [RegisterSessionController::class, 'approve'])->name('approve');
        Route::post('reopen', [RegisterSessionController::class, 'reopen'])->name('reopen');
        Route::get('report', [RegisterSessionController::class, 'report'])->name('report');

        Route::get('movements', [RegisterSessionController::class, 'movements'])->name('movements.index');
        Route::post('movements', [RegisterSessionController::class, 'storeMovement'])->name('movements.store');
    });
});
