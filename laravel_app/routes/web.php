<?php

use App\Http\Controllers\TradeSetupController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/trade-setups', [TradeSetupController::class, 'index']);
