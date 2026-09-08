<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\IoTControllerController;
use App\Http\Controllers\RoomController;

Route::apiResource('controllers', IoTControllerController::class);
Route::apiResource('rooms', RoomController::class);
Route::apiResource('devices', DeviceController::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'backend',
        'version' => '2.0.0',
    ]);
});
