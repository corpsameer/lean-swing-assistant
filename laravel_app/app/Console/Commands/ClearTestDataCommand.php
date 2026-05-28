<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearTestDataCommand extends Command
{
    protected $signature = 'app:clear-test-data
        {--include-symbols : Also truncate symbols table (dangerous for production universe)}
        {--force : Skip confirmation prompts}';

    protected $description = 'Safely clear generated test/runtime trading data while preserving core system tables.';

    /** @var array<int, string> */
    private array $runtimeTables = [
        'orders',
        'trade_setups',
        'trade_reviews',
        'watchlist_candidates',
        'market_snapshots',
        'prompt_logs',
        'runs',
    ];

    public function handle(): int
    {
        $includeSymbols = (bool) $this->option('include-symbols');
        $force = (bool) $this->option('force');

        $tablesToClean = $this->runtimeTables;

        if ($includeSymbols) {
            $tablesToClean[] = 'symbols';
            $this->warn('WARNING: --include-symbols was provided. Universe symbols will be removed.');
        }

        $existingTables = array_values(array_filter(
            $tablesToClean,
            static fn (string $table): bool => Schema::hasTable($table)
        ));

        if ($existingTables === []) {
            $this->info('No target runtime tables found. Nothing to clean.');

            return self::SUCCESS;
        }

        $this->line('Tables to truncate:');
        foreach ($existingTables as $table) {
            $this->line('- '.$table);
        }

        $beforeCounts = $this->tableCounts($existingTables);
        $this->newLine();
        $this->line('Row counts before cleanup:');
        $this->renderCounts($beforeCounts);

        $requiresConfirmation = app()->environment('production') || ! $force;
        if ($requiresConfirmation && ! $this->confirm('Proceed with truncating these tables?', false)) {
            $this->warn('Cleanup cancelled.');

            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($existingTables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $afterCounts = $this->tableCounts($existingTables);

        $this->newLine();
        $this->info('Cleanup complete.');
        $this->line('Row counts after cleanup:');
        $this->renderCounts($afterCounts);

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $tables
     * @return array<string, int>
     */
    private function tableCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private function renderCounts(array $counts): void
    {
        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %s: %d', $table, $count));
        }
    }
}
