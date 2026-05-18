<?php

namespace App\Console\Commands;

use App\Services\PaperTradeStatusSyncService;
use Illuminate\Console\Command;

class SyncPaperTradeStatus extends Command
{
    protected $signature = 'trades:sync-paper-status';

    protected $description = 'Sync paper broker order statuses into local orders and trade setup states.';

    public function __construct(private readonly PaperTradeStatusSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->syncService->sync();

        $this->line('orders checked: '.$summary['orders_checked']);
        $this->line('orders updated: '.$summary['orders_updated']);
        $this->line('trade setups entered: '.$summary['trade_setups_entered']);
        $this->line('trade setups cancelled: '.$summary['trade_setups_cancelled']);
        $this->line('trade setups closed: '.$summary['trade_setups_closed']);
        $this->line('errors: '.$summary['errors']);

        return self::SUCCESS;
    }
}
