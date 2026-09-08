<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => 'home')->name('home');

Route::prefix('admin')->name('admin.')->middleware(['auth'])->group(function () {
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');

    // Nested, so the URI is the concatenation of both prefixes. Grepping for 'reports' finds
    // the line and tells you nothing about the path it answers on.
    Route::prefix('reports')->group(function () {
        Route::get('/monthly', [OrderController::class, 'show'])->name('reports.monthly');
    });
});

Route::group(['prefix' => 'legacy', 'namespace' => 'Legacy'], function () {
    // A bare controller name inside a namespaced group: a single-action controller, written
    // the way the framework wanted before callable arrays.
    Route::get('/orders', 'OrderListController')->name('legacy.orders');
});
