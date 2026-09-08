<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Tracer\Tracer;
use PHPUnit\Runner\Extension\Facade as ExtensionFacade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use Threadable\QalityPlus\PhpUnit\QalityPlusExtension;
use Threadable\QalityPlus\Tests\TestCase;

final class QalityPlusExtensionTest extends TestCase
{
    public function test_it_ignores_a_sealed_event_facade_for_parallel_controller_bootstraps(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-plus-sealed-'.bin2hex(random_bytes(4));
        $facade = new class implements ExtensionFacade
        {
            public int $registrationAttempts = 0;

            public function registerSubscribers(Subscriber ...$subscribers): void
            {
                $this->registrationAttempts++;

                throw new EventFacadeIsSealedException;
            }

            public function registerSubscriber(Subscriber $subscriber): void
            {
                throw new EventFacadeIsSealedException;
            }

            public function registerTracer(Tracer $tracer): void
            {
                throw new EventFacadeIsSealedException;
            }

            public function replaceOutput(): void {}

            public function replaceProgressOutput(): void {}

            public function replaceResultOutput(): void {}

            public function requireCodeCoverageCollection(): void {}
        };
        $configuration = (new ReflectionClass(Configuration::class))->newInstanceWithoutConstructor();

        (new QalityPlusExtension)->bootstrap(
            $configuration,
            $facade,
            ParameterCollection::fromArray(['directory' => $directory]),
        );

        self::assertSame(1, $facade->registrationAttempts);
        self::assertDirectoryDoesNotExist($directory);
    }
}
