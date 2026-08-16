<?php

use App\Http\Controllers\Api\DeleteObjectController;
use App\Http\Controllers\Api\GetAllRecordsController;
use App\Http\Controllers\Api\ObjectHistoryController;
use App\Http\Controllers\Api\ShowObjectController;
use App\Http\Controllers\Api\StoreObjectController;
use App\ValueObjects\Key;
use Illuminate\Support\Facades\Route;

// A key is one opaque path segment; the pattern is what keeps a hostile
// segment from ever reaching a handler. It comes from the Key value object so
// that routing and validation cannot disagree about what a key is.
$key = Key::PATTERN;

Route::post('/object', StoreObjectController::class);

// Must be registered before the {key} wildcard below, otherwise this
// literal segment would be swallowed as a key lookup.
Route::get('/object/get_all_records/{page?}', GetAllRecordsController::class)
    ->where('page', '\d+');

Route::get('/object/{key}/history', ObjectHistoryController::class)
    ->where('key', $key);

Route::get('/object/{key}', ShowObjectController::class)
    ->where('key', $key);

Route::delete('/object/{key}', DeleteObjectController::class)
    ->where('key', $key);
