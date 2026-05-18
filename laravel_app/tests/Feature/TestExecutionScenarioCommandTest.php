<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestExecutionScenarioCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_paper_scenario_uses_central_execution_summary_output(): void
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
            ->expectsOutputToContain('execution_driver=simulated')
            ->expectsOutputToContain('broker_called=false')
            ->expectsOutputToContain('order_created=true')
            ->expectsOutputToContain('status=simulated_pending')
            ->expectsOutputToContain('message=simulated order created; no broker order placed')
            ->doesntExpectOutputToContain('Order placement script path is missing or invalid')
            ->assertExitCode(0);
    }
}
