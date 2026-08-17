<?php

use App\Http\Controllers\Api\DeleteObjectController;
use App\Http\Controllers\Api\GetAllRecordsController;
use App\Http\Controllers\Api\ObjectHistoryController;
use App\Http\Controllers\Api\ShowObjectController;
use App\Http\Controllers\Api\StoreObjectController;
use App\ValueObjects\Key;
use Illuminate\Support\Facades\Route;

$key = Key::PATTERN;

Route::post('/object', StoreObjectController::class);

Route::get('/object/get_all_records/{page?}', GetAllRecordsController::class)
    ->where('page', '\d+');

Route::get('/object/{key}/history', ObjectHistoryController::class)
    ->where('key', $key);

Route::get('/object/{key}', ShowObjectController::class)
    ->where('key', $key);

Route::delete('/object/{key}', DeleteObjectController::class)
    ->where('key', $key);
