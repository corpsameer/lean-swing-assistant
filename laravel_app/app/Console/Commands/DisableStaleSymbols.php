<?php

namespace App\Console\Commands;

use App\Models\Symbol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DisableStaleSymbols extends Command
{
    protected $signature = 'symbols:disable-stale
                            {--days=30 : Disable active symbols last seen before this many days}
                            {--force : Run without confirmation in production}';

    protected $description = 'Disable stale symbols based on last_seen_at age';

    public function handle(): int
    {
        if (! Schema::hasColumn('symbols', 'last_seen_at')) {
            $this->warn('Skipping: symbols.last_seen_at column does not exist.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);

        if (app()->isProduction() && ! $this->option('force') && ! $this->confirm('Disable stale symbols in production?', false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $affected = Symbol::query()
            ->where('is_active', true)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $threshold)
            ->update(['is_active' => false]);

        $this->info('Stale symbol disable completed.');
        $this->line('threshold: '.$threshold->toDateTimeString());
        $this->line('symbols disabled: '.$affected);

        return self::SUCCESS;
    }
}
