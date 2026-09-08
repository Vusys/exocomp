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

Route::post('/orders', [OrderController::class, 'store'])->name('place-order');

// A leading backslash means the name is already absolute, so the root namespace must not be
// joined on in front of it — the framework checks for exactly this.
Route::get('/exports', '\App\Http\Controllers\OrderController@index')->name('exports');

Route::group(['prefix' => 'legacy', 'namespace' => 'Legacy'], function () {
    // A bare controller name inside a namespaced group: a single-action controller, written
    // the way the framework wanted before callable arrays.
    Route::get('/orders', 'OrderListController')->name('legacy.orders');
});
