<?php

namespace Tests\Feature;

use App\Services\IntradayRefreshService;
use App\Services\PromptCIntradayValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IntradayValidateRefreshOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exits_cleanly_when_no_active_symbols_exist(): void
    {
        $refreshService = $this->mock(IntradayRefreshService::class);
        $refreshService->shouldReceive('resolveActiveSymbols')->once()->andReturn([]);
        $refreshService->shouldNotReceive('fetchForSymbols');
        $refreshService->shouldNotReceive('ingestFromJsonPath');

        $validationService = $this->mock(PromptCIntradayValidationService::class);
        $validationService->shouldNotReceive('run');

        $this->artisan('prompt:intraday-validate')
            ->expectsOutputToContain('active symbols resolved: 0')
            ->expectsOutput('No active symbols found. Exiting cleanly.')
            ->assertSuccessful();
    }

    public function test_it_fails_clearly_when_intraday_fetch_fails(): void
    {
        $refreshService = $this->mock(IntradayRefreshService::class);
        $refreshService->shouldReceive('resolveActiveSymbols')->once()->andReturn(['AAPL']);
        $refreshService->shouldReceive('fetchForSymbols')->once()->with(['AAPL'])->andThrow(new RuntimeException('Intraday fetch failed: timeout'));
        $refreshService->shouldNotReceive('ingestFromJsonPath');

        $validationService = $this->mock(PromptCIntradayValidationService::class);
        $validationService->shouldNotReceive('run');

        $this->artisan('prompt:intraday-validate')
            ->expectsOutputToContain('active symbols resolved: 1')
            ->expectsOutputToContain('fetching intraday data...')
            ->expectsOutput('Intraday validate prompt failed: Intraday fetch failed: timeout')
            ->assertFailed();
    }
}
