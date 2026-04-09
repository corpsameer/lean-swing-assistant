<?php

namespace Tests\Unit;

use App\Services\IntradayRefreshService;
use App\Services\MarketDataIngestionService;
use Tests\TestCase;

class IntradayRefreshServiceTest extends TestCase
{
    public function test_it_preserves_windows_absolute_output_path_without_prefixing_base_path(): void
    {
        config()->set('services.intraday_fetch.output_path', 'C:\\Personal\\projects\\lean-swing-assistant\\laravel_app\\storage\\app\\intraday_snapshot.json');

        $service = new IntradayRefreshService($this->mock(MarketDataIngestionService::class));
        $method = new \ReflectionMethod($service, 'resolveOutputPath');
        $method->setAccessible(true);

        $resolved = $method->invoke($service);

        $this->assertSame('C:\\Personal\\projects\\lean-swing-assistant\\laravel_app\\storage\\app\\intraday_snapshot.json', $resolved);
    }
}
