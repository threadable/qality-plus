<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use Throwable;

final class ExtensionDiagnostics
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function report(string $stage, Throwable $exception, array $context = []): void
    {
        $context = [
            'pid' => getmypid(),
            ...$context,
        ];

        $lines = [
            sprintf('[qality-plus] PHPUnit extension error during %s.', $stage),
            sprintf('Exception: %s: %s', $exception::class, $exception->getMessage()),
            sprintf('Location: %s:%d', $exception->getFile(), $exception->getLine()),
            'Context: '.self::formatContext($context),
            'Trace:',
            $exception->getTraceAsString(),
        ];

        self::write(implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private static function formatContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = $key.'='.self::formatValue($value);
        }

        return implode(', ', $parts);
    }

    private static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"'.addcslashes($value, '\\"').'"',
            default => (string) $value,
        };
    }

    private static function write(string $message): void
    {
        $stream = defined('STDERR') ? STDERR : null;

        if (is_resource($stream) && @fwrite($stream, $message) !== false) {
            return;
        }

        @error_log(rtrim($message));
    }
}
