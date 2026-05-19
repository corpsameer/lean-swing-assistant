<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TradeSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/trade-setups', [TradeSetupController::class, 'index']);
Route::get('/admin/orders', [OrderController::class, 'index']);
Route::get('/admin/trades', [TradeController::class, 'index']);
