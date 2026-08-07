<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Feature;

use Threadable\QalityPlus\Tests\TestCase;

final class CommandsTest extends TestCase
{
    private string $resultPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resultPath = tempnam(sys_get_temp_dir(), 'qality-results-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->resultPath)) {
            unlink($this->resultPath);
        }

        parent::tearDown();
    }

    public function test_create_command_can_validate_results_through_the_testbench_application(): void
    {
        file_put_contents($this->resultPath, json_encode([
            'schema_version' => 1,
            'run_id' => 'run-1',
            'sequence' => 1,
            'recorded_at' => '2026-08-07T12:00:00+00:00',
            'status' => 'passed',
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => null,
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        $this->artisan('qality:create-test-cases', [
            'path' => $this->resultPath,
            '--branch' => 'feature/PROJ-123-checkout',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Validated 1 eligible test case(s) for PROJ-123')
            ->assertExitCode(0);
    }
}
