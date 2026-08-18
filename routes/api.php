<?php

use App\Http\Controllers\Api\DeleteObjectController;
use App\Http\Controllers\Api\GetAllKeysController;
use App\Http\Controllers\Api\GetAllRecordsController;
use App\Http\Controllers\Api\ObjectHistoryController;
use App\Http\Controllers\Api\ReplaceObjectController;
use App\Http\Controllers\Api\ShowObjectController;
use App\Http\Controllers\Api\StoreObjectController;
use App\ValueObjects\Key;
use Illuminate\Support\Facades\Route;

$key = Key::routePattern();

Route::get('/object/{key}', ShowObjectController::class)
    ->where('key', $key);

Route::get('/object/{key}/history', ObjectHistoryController::class)
    ->where('key', $key);

// Before the paged listing, though the two could not collide anyway: {page} is
// constrained to digits, so 'keys' can never be read as a page number.
Route::get('/object/get_all_records/keys', GetAllKeysController::class);

Route::get('/object/get_all_records/{page?}', GetAllRecordsController::class)
    ->where('page', '\d+');

Route::post('/object', StoreObjectController::class);

// A real verb, not a spoof: AppServiceProvider disables Symfony's _method
// override, so a PUT has to arrive as a PUT.
Route::put('/object/{key}', ReplaceObjectController::class)
    ->where('key', $key);

Route::delete('/object/{key}', DeleteObjectController::class)
    ->where('key', $key);
