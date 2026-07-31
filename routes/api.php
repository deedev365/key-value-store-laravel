<?php

use App\Http\Controllers\Api\ObjectController;
use Illuminate\Support\Facades\Route;

Route::post('/object', [ObjectController::class, 'store']);

// Must be registered before the {key} wildcard below, otherwise this
// literal segment would be swallowed as a key lookup.
Route::get('/object/get_all_records/{page?}', [ObjectController::class, 'getAllRecords'])
    ->where('page', '\d+');

Route::get('/object/{key}/history', [ObjectController::class, 'history'])
    ->where('key', '[A-Za-z0-9_.-]+');

Route::get('/object/{key}', [ObjectController::class, 'show'])
    ->where('key', '[A-Za-z0-9_.-]+');

Route::delete('/object/{key}', [ObjectController::class, 'removeKey'])
    ->where('key', '[A-Za-z0-9_.-]+');
