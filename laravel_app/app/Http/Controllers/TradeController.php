<?php

namespace App\Http\Controllers;

use App\Models\TradeSetup;
use Illuminate\View\View;

class TradeController extends Controller
{
    public function index(): View
    {
        $tradeSetups = TradeSetup::query()
            ->with([
                'symbol:id,symbol',
                'orders' => fn ($query) => $query->orderByDesc('id'),
                'sourceCandidate:id,setup_type',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(function (TradeSetup $tradeSetup) {
                $tradeSetup->setRelation('latestOrder', $tradeSetup->orders->first());

                return $tradeSetup;
            });

        return view('trades.index', [
            'tradeSetups' => $tradeSetups,
        ]);
    }
}
