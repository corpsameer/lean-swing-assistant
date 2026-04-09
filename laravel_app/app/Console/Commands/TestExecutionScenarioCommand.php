<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Run;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Models\WatchlistCandidate;
use App\Services\PaperTradeExecutionService;
use Illuminate\Console\Command;

class TestExecutionScenarioCommand extends Command
{
    protected $signature = 'trade:execution-scenario-test
        {scenario : disabled|dry-run|paper}
        {setup_type : breakout|pullback}
        {--symbol=AAPL : Symbol to use (paper scenario requires a real IBKR symbol)}
        {--entry=100.00 : Entry price}
        {--stop=98.00 : Stop price}
        {--target=104.00 : Target1 price}
        {--quantity=1 : Paper order quantity}
        {--force-paper : Required flag before actual paper transmission}';

    protected $description = 'Insert dummy trade setup data and run T11.1 execution in disabled, dry-run, or paper mode.';

    public function handle(PaperTradeExecutionService $executionService): int
    {
        $scenario = strtolower((string) $this->argument('scenario'));
        $setupType = strtolower((string) $this->argument('setup_type'));

        if (! in_array($scenario, ['disabled', 'dry-run', 'paper'], true)) {
            $this->error('scenario must be one of: disabled, dry-run, paper');

            return self::FAILURE;
        }

        if (! in_array($setupType, ['breakout', 'pullback'], true)) {
            $this->error('setup_type must be breakout or pullback');

            return self::FAILURE;
        }

        if ($scenario === 'paper' && ! (bool) $this->option('force-paper')) {
            $this->error('paper scenario requires --force-paper to avoid accidental transmission.');

            return self::FAILURE;
        }

        $symbolText = strtoupper(trim((string) $this->option('symbol')));
        $entryPrice = (float) $this->option('entry');
        $stopPrice = (float) $this->option('stop');
        $targetPrice = (float) $this->option('target');
        $quantity = (float) $this->option('quantity');

        if ($symbolText === '' || $entryPrice <= 0 || $stopPrice <= 0 || $targetPrice <= 0 || $quantity <= 0) {
            $this->error('symbol, entry, stop, target, and quantity must all be valid positive values.');

            return self::FAILURE;
        }

        if ($scenario === 'paper' && preg_match('/^(TEST|T\d)/', $symbolText) === 1) {
            $this->error('paper scenario requires a real IBKR-tradable symbol (example: AAPL, MSFT). Dummy symbols are rejected.');

            return self::FAILURE;
        }

        config()->set('services.trade_execution.broker_trading_mode', 'paper');
        config()->set('services.trade_execution.enabled', $scenario !== 'disabled');
        config()->set('services.trade_execution.dry_run', $scenario !== 'paper');
        config()->set('services.trade_execution.paper_order_quantity', $quantity);

        $symbol = Symbol::query()->firstOrCreate(
            ['symbol' => $symbolText],
            [
                'company_name' => $symbolText.' Dummy Co',
                'exchange' => 'NASDAQ',
                'sector' => 'Testing',
                'is_active' => true,
            ]
        );

        $run = Run::query()->create([
            'run_type' => 'execution_test',
            'status' => 'completed',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
            'meta_json' => ['scenario' => $scenario],
        ]);

        $candidate = WatchlistCandidate::query()->create([
            'run_id' => $run->id,
            'symbol_id' => $symbol->id,
            'stage' => 'weekend',
            'status' => 'keep',
            'setup_type' => $setupType,
            'reasoning_text' => 'T11.1 scenario test dummy candidate',
            'created_at' => now('UTC'),
        ]);

        $tradeSetup = TradeSetup::query()->create([
            'symbol_id' => $symbol->id,
            'source_candidate_id' => $candidate->id,
            'status' => 'planned',
            'entry_price' => $entryPrice,
            'stop_price' => $stopPrice,
            'target1_price' => $targetPrice,
            'target2_price' => $targetPrice + 1,
            'notes' => 'T11.1 scenario test dummy trade setup',
        ]);

        $this->info('Created dummy trade setup: '.$tradeSetup->id);
        $this->line(sprintf('Scenario=%s SetupType=%s Symbol=%s', $scenario, $setupType, $symbolText));

        $executionResult = $executionService->executeForSetup($tradeSetup, $symbolText);

        $order = Order::query()->where('trade_setup_id', $tradeSetup->id)->latest('id')->first();

        if ($order === null) {
            if ($scenario === 'disabled' || ($executionResult['status'] ?? '') === 'skipped') {
                $this->warn('No order row created (expected for disabled scenario).');

                return self::SUCCESS;
            }

            $this->error('No order row created for an enabled scenario.');
            $this->line('Execution status: '.($executionResult['status'] ?? 'unknown'));
            $this->line('Execution message: '.($executionResult['message'] ?? 'n/a'));
            $this->newLine();
            $this->warn('Tip: verify EXECUTION_SCRIPT_PATH and run `php artisan config:clear` after editing .env.');

            return self::FAILURE;
        }

        $payload = [
            'order_id' => $order->id,
            'trade_setup_id' => $tradeSetup->id,
            'status' => $order->status,
            'broker_order_id' => $order->broker_order_id,
            'order_type' => $order->order_type,
            'side' => $order->side,
            'quantity' => (float) $order->quantity,
            'limit_price' => $order->limit_price,
            'stop_price' => $order->stop_price,
            'meta_json' => $order->meta_json,
        ];

        $this->info('Order record created:');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
