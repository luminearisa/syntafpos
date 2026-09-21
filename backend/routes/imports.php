<?php

use App\Http\Controllers\Api\V1\Imports\ImportController;

// Bulk import is a two-step preview-then-commit flow, so it sits apart from the
// resource controllers: one endpoint reports what a file would do, the other
// does it (§30).
Route::prefix('imports')->name('imports.')->group(function () {
    Route::post('preview', [ImportController::class, 'preview'])->name('preview');
    Route::post('commit', [ImportController::class, 'commit'])->name('commit');
});
