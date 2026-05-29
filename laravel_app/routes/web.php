<?php

use App\Http\Controllers\Admin\CommandRunsController;
use App\Http\Controllers\Admin\SymbolsController;
use App\Http\Controllers\Admin\WatchlistController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TradeSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

Route::middleware('auth')->prefix('admin')->group(function () {
    Route::get('/trade-setups', [TradeSetupController::class, 'index']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/trades', [TradeController::class, 'index']);
    Route::get('/analytics', [AnalyticsController::class, 'index']);
    Route::get('/symbols', [SymbolsController::class, 'index']);
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::get('/command-runs', [CommandRunsController::class, 'index']);
    Route::get('/command-runs/{id}', [CommandRunsController::class, 'show'])->whereNumber('id');
});
