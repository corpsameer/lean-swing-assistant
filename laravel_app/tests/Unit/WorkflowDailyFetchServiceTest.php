<?php

namespace Tests\Unit;

use App\Services\WorkflowDailyFetchService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkflowDailyFetchServiceTest extends TestCase
{
    public function test_it_fetches_daily_bars_in_batches_and_merges_snapshot_metadata(): void
    {
        $basePath = $this->writeFakeFetcher(<<<'PHP_SCRIPT'
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

        $this->configureFetchEnv($basePath, [
            'DAILY_FETCH_BATCH_SIZE' => '2',
            'DAILY_FETCH_BATCH_TIMEOUT_SECONDS' => '30',
            'DAILY_FETCH_MAX_TOTAL_SECONDS' => '300',
        ]);

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
        $this->assertSame(0, $result['failed_batches']);
        $this->assertSame(0, $result['failed_symbols']);
        $this->assertFalse($result['partial']);
        $this->assertTrue($result['met_min_valid_symbols']);
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_001.json'));
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_002.json'));
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_003.json'));

        $mergedPayload = json_decode(file_get_contents($result['snapshot_path']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('batched_daily_fetch', $mergedPayload['source']);
        $this->assertSame(3, $mergedPayload['batch_count']);
        $this->assertSame(4, $mergedPayload['valid_symbols']);
        $this->assertFalse($mergedPayload['partial']);
        $this->assertSame('error', $mergedPayload['symbols'][2]['status']);
        $this->assertSame('No daily bars returned.', $mergedPayload['symbols'][2]['error']);
        $this->assertContains('line:Total batches: 3', $messages);
    }

    public function test_it_retries_timed_out_batches_in_smaller_chunks_and_merges_partial_success(): void
    {
        $basePath = $this->writeFakeFetcher(<<<'PHP_SCRIPT'
<?php
$args = $argv;
array_shift($args);
$outputIndex = array_search('--output', $args, true);
$output = $args[$outputIndex + 1];
$symbols = array_slice($args, 0, $outputIndex);
if (count($symbols) > 2) {
    sleep(2);
    exit(0);
}
$payloadSymbols = [];
foreach ($symbols as $symbol) {
    if ($symbol === 'FAIL') {
        sleep(2);
        exit(0);
    }
    $payloadSymbols[] = [
        'symbol' => $symbol,
        'status' => 'ok',
        'error' => null,
        'bars' => [['date' => '2026-05-27', 'close' => 100]],
    ];
}
file_put_contents($output, json_encode([
    'mode' => 'paper',
    'fetched_at_utc' => '2026-05-28T00:00:00Z',
    'symbols' => $payloadSymbols,
], JSON_PRETTY_PRINT));
PHP_SCRIPT);

        $this->configureFetchEnv($basePath, [
            'DAILY_FETCH_BATCH_SIZE' => '4',
            'DAILY_FETCH_BATCH_TIMEOUT_SECONDS' => '1',
            'DAILY_FETCH_MAX_TOTAL_SECONDS' => '300',
            'DAILY_FETCH_RETRY_FAILED_BATCHES' => 'true',
            'DAILY_FETCH_RETRY_BATCH_SIZE' => '2',
            'DAILY_FETCH_MAX_BATCH_RETRIES' => '1',
        ]);

        $messages = [];
        $service = app(WorkflowDailyFetchService::class);
        $result = $service->fetchDailyBarsBatchedToDefaultSnapshotPath(
            ['AAPL', 'MSFT', 'NVDA', 'TSLA', 'FAIL'],
            function (string $level, string $message) use (&$messages): void {
                $messages[] = $level.':'.$message;
            }
        );

        $this->assertSame(4, $result['valid_symbols']);
        $this->assertSame(1, $result['failed_symbols']);
        $this->assertSame(['FAIL'], $result['failed_symbols_preview']);
        $this->assertTrue($result['partial']);
        $this->assertTrue($result['met_min_valid_symbols']);
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_001_retry_001.json'));
        $this->assertFileExists(storage_path('app/daily_snapshot_parts/daily_snapshot_part_001_retry_002.json'));
        $this->assertContains('warn:Batch 1 will be retried with retry batch size 2', $messages);

        $mergedPayload = json_decode(file_get_contents($result['snapshot_path']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($mergedPayload['partial']);
        $this->assertSame(1, $mergedPayload['failed_symbols']);
        $this->assertSame(['FAIL'], $mergedPayload['failed_symbols_preview']);
    }

    public function test_it_reuses_existing_valid_parts_when_resume_mode_is_enabled(): void
    {
        $basePath = $this->writeFakeFetcher(<<<'PHP_SCRIPT'
<?php
exit(9);
PHP_SCRIPT);

        $partsDirectory = storage_path('app/daily_snapshot_parts');
        File::ensureDirectoryExists($partsDirectory);
        file_put_contents($partsDirectory.'/daily_snapshot_part_001.json', json_encode([
            'mode' => 'paper',
            'fetched_at_utc' => '2026-05-28T00:00:00Z',
            'symbols' => [[
                'symbol' => 'AAPL',
                'status' => 'ok',
                'error' => null,
                'bars' => [['date' => '2026-05-27', 'close' => 100]],
            ]],
        ], JSON_PRETTY_PRINT));

        $this->configureFetchEnv($basePath, [
            'DAILY_FETCH_BATCH_SIZE' => '1',
            'DAILY_FETCH_CLEAR_PARTS_BEFORE_RUN' => 'false',
            'DAILY_FETCH_SKIP_EXISTING_PARTS' => 'true',
            'DAILY_FETCH_STOP_ON_BATCH_FAILURE' => 'false',
            'DAILY_FETCH_RETRY_FAILED_BATCHES' => 'false',
        ]);

        $messages = [];
        $service = app(WorkflowDailyFetchService::class);
        $result = $service->fetchDailyBarsBatchedToDefaultSnapshotPath(
            ['AAPL'],
            function (string $level, string $message) use (&$messages): void {
                $messages[] = $level.':'.$message;
            }
        );

        $this->assertSame(1, $result['valid_symbols']);
        $this->assertFalse($result['partial']);
        $this->assertContains('line:Batch 1 reused existing part: valid=1 errors=0 path='.storage_path('app/daily_snapshot_parts/daily_snapshot_part_001.json'), $messages);
    }

    /**
     * @param  array<string,string>  $overrides
     */
    private function configureFetchEnv(string $basePath, array $overrides = []): void
    {
        $defaults = [
            'EXECUTION_PYTHON_EXECUTABLE' => PHP_BINARY,
            'PYTHON_IBKR_BASE_PATH' => $basePath,
            'DAILY_FETCH_BATCH_SIZE' => '2',
            'DAILY_FETCH_BATCH_TIMEOUT_SECONDS' => '30',
            'DAILY_FETCH_MAX_TOTAL_SECONDS' => '300',
            'DAILY_FETCH_MIN_VALID_SYMBOLS' => '1',
            'DAILY_FETCH_STOP_ON_BATCH_FAILURE' => 'false',
            'DAILY_FETCH_RETRY_FAILED_BATCHES' => 'true',
            'DAILY_FETCH_RETRY_BATCH_SIZE' => '1',
            'DAILY_FETCH_MAX_BATCH_RETRIES' => '1',
            'DAILY_FETCH_SKIP_EXISTING_PARTS' => 'true',
            'DAILY_FETCH_CLEAR_PARTS_BEFORE_RUN' => 'true',
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            $this->setEnv($key, $value);
        }
    }

    private function writeFakeFetcher(string $script): string
    {
        $basePath = storage_path('framework/testing/fake_python_ibkr');
        File::deleteDirectory($basePath);
        File::deleteDirectory(storage_path('app/daily_snapshot_parts'));
        File::ensureDirectoryExists($basePath.'/scripts');
        file_put_contents($basePath.'/scripts/fetch_daily_bars.py', $script);

        return $basePath;
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
