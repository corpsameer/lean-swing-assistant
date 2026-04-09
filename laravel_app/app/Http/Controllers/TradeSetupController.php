<?php

namespace App\Http\Controllers;

use App\Models\TradeSetup;
use Illuminate\View\View;

class TradeSetupController extends Controller
{
    public function index(): View
    {
        $tradeSetups = TradeSetup::query()
            ->with(['symbol:id,symbol', 'sourceCandidate:id,setup_type,stage'])
            ->latest()
            ->get();

        return view('trade_setups.index', [
            'tradeSetups' => $tradeSetups,
        ]);
    }
}
