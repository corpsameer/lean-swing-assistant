<?php

namespace Tests\Unit;

use App\Services\WorkflowDailyFetchService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkflowDailyFetchServiceTest extends TestCase
{
    public function test_it_fetches_daily_bars_in_batches_and_merges_snapshot_metadata(): void
    {
        $basePath = storage_path('framework/testing/fake_python_ibkr');
        File::deleteDirectory($basePath);
        File::ensureDirectoryExists($basePath.'/scripts');

        $scriptPath = $basePath.'/scripts/fetch_daily_bars.py';
        file_put_contents($scriptPath, <<<'PHP_SCRIPT'
<?php
$args = $argv;
array_shift($args);
$outputIndex = array_search('--output', $args, true);
$output = $args[$outputIndex + 1];
$symbols = array_slice($args, 0, $outputIndex);
$payloadSymbols = [];
foreach ($symbols as $symbol) {
    $payloadSymbols[] = [
        'symbol' => $symbol,
        'status' => 'ok',
        'error' => null,
        'bars' => $symbol === 'EMPTY' ? [] : [['date' => '2026-05-27', 'close' => 100]],
    ];
}
file_put_contents($output, json_encode([
    'mode' => 'paper',
    'fetched_at_utc' => '2026-05-28T00:00:00Z',
    'symbols' => $payloadSymbols,
], JSON_PRETTY_PRINT));
PHP_SCRIPT);

        $this->setEnv('EXECUTION_PYTHON_EXECUTABLE', PHP_BINARY);
        $this->setEnv('PYTHON_IBKR_BASE_PATH', $basePath);
        $this->setEnv('DAILY_FETCH_BATCH_SIZE', '2');
        $this->setEnv('DAILY_FETCH_BATCH_TIMEOUT_SECONDS', '30');
        $this->setEnv('DAILY_FETCH_MAX_TOTAL_SECONDS', '300');
        $this->setEnv('DAILY_FETCH_MIN_VALID_SYMBOLS', '1');
        $this->setEnv('DAILY_FETCH_STOP_ON_BATCH_FAILURE', 'false');

        $messages = [];
        $service = app(WorkflowDailyFetchService::class);
        $result = $service->fetchDailyBarsBatchedToDefaultSnapshotPath(
            ['AAPL', 'MSFT', 'EMPTY', 'NVDA', 'TSLA'],
            function (string $level, string $message) use (&$messages): void {
                $messages[] = $level.':'.$message;
            }
        );

        $this->assertSame(3, $result['batch_count']);
        $this->assertSame(5, $result['symbols_requested']);
        $this->assertSame(5, $result['symbols_returned']);
        $this->assertSame(4, $result['valid_symbols']);
        $this->assertSame(1, $result['error_symbols']);
        $this->assertTrue($result['met_min_valid_symbols']);
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_001.json'));
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_002.json'));
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_003.json'));

        $mergedPayload = json_decode(file_get_contents($result['snapshot_path']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('batched_daily_fetch', $mergedPayload['source']);
        $this->assertSame(3, $mergedPayload['batch_count']);
        $this->assertSame(4, $mergedPayload['valid_symbols']);
        $this->assertSame('error', $mergedPayload['symbols'][2]['status']);
        $this->assertSame('No daily bars returned.', $mergedPayload['symbols'][2]['error']);
        $this->assertContains('line:Total batches: 3', $messages);
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
