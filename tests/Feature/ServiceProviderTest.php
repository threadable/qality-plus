<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Feature;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\QalityClient;
use Threadable\QalityPlus\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_the_package_merges_configuration_and_registers_api_clients(): void
    {
        self::assertSame(1, config('qality.results.schema_version'));
        self::assertSame(300, config('qality.publisher.timeout'));
        self::assertSame(50, config('qality.qality.import_batch_size'));
        self::assertSame(0, config('qality.publisher.retries'));
        self::assertSame('QAlity Test', config('qality.publisher.linking.type'));
        self::assertInstanceOf(QalityClient::class, $this->app->make(QalityClient::class));
        self::assertInstanceOf(JiraClient::class, $this->app->make(JiraClient::class));
    }

    public function test_the_package_registers_both_artisan_commands(): void
    {
        $commands = $this->app->make(ConsoleKernel::class)->all();

        self::assertArrayHasKey('qality:create-test-cases', $commands);
        self::assertArrayHasKey('qality:publish', $commands);
    }
}
