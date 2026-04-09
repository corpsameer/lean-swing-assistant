<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestExecutionScenarioCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_paper_scenario_reports_error_when_execution_fails_before_order_row_is_created(): void
    {
        config()->set('services.trade_execution.script_path', base_path('tests/Fixtures/does_not_exist.py'));
        config()->set('services.trade_execution.python_executable', 'python');

        $this->artisan('trade:execution-scenario-test', [
            'scenario' => 'paper',
            'setup_type' => 'breakout',
            '--force-paper' => true,
            '--symbol' => 'AAPL',
            '--entry' => '184.50',
            '--stop' => '182.50',
            '--target' => '188.00',
            '--quantity' => '1',
        ])
            ->expectsOutputToContain('No order row created for an enabled scenario.')
            ->expectsOutputToContain('Execution status: error')
            ->expectsOutputToContain('Order placement script path is missing or invalid')
            ->assertExitCode(1);
    }
}
