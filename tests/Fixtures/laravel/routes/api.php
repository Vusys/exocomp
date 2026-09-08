<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    // Seven routes the framework generates and the source does not list. A reader that showed
    // only this line would be hiding six endpoints.
    Route::resource('orders', OrderController::class)->only(['index', 'show']);

    Route::get('/health', ['uses' => 'HealthController', 'as' => 'api.health']);

    // The URI is built at runtime, so there is nothing to report but the fact of it.
    Route::get(config('services.timeout') . '/dynamic', [OrderController::class, 'index']);
});
