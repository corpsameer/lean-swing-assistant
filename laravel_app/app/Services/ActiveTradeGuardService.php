<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TradeSetup;

class ActiveTradeGuardService
{
    /**
     * @return array{has_active:bool,trade_setup_id:int|null,order_id:int|null}
     */
    public function findActiveForSymbol(int $symbolId): array
    {
        $activeSetup = TradeSetup::query()
            ->where('symbol_id', $symbolId)
            ->whereIn('status', ['planned', 'entered', 'open'])
            ->latest('id')
            ->first();

        $activeOrder = Order::query()
            ->where('symbol_id', $symbolId)
            ->whereIn('status', [
                'simulated_pending',
                'simulated_entered',
                'submitted_paper',
                'partially_filled_paper',
                'inactive_broker',
            ])
            ->latest('id')
            ->first();

        return [
            'has_active' => $activeSetup !== null || $activeOrder !== null,
            'trade_setup_id' => $activeSetup?->id,
            'order_id' => $activeOrder?->id,
        ];
    }

    public function hasActiveSetupOrOrderForSymbol(int $symbolId): bool
    {
        return $this->findActiveForSymbol($symbolId)['has_active'];
    }
}

