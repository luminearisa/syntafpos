<?php

use App\Http\Controllers\Api\V1\Exports\ExportController;

// An export is a read over an entity the reports already cover, delivered in
// the format the client asks for; the default is CSV (§31).
Route::get('exports/{entity}', [ExportController::class, 'export'])
    ->where('entity', '[a-z-]+')
    ->name('exports.export');
