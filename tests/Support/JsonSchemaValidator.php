<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Support;

use PHPUnit\Framework\Assert;

final class JsonSchemaValidator
{
    /**
     * This intentionally supports the small JSON Schema subset used by the
     * upstream contract fixtures: type, required, properties, items, enum,
     * minItems and minLength.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function assertValid(mixed $value, array $schema, string $path = '$'): void
    {
        if (isset($schema['type'])) {
            self::assertType($value, $schema['type'], $path);
        }

        if (isset($schema['enum'])) {
            Assert::assertContains($value, $schema['enum'], sprintf('%s must match the contract enum.', $path));
        }

        if (is_string($value) && isset($schema['minLength'])) {
            Assert::assertGreaterThanOrEqual(
                (int) $schema['minLength'],
                strlen($value),
                sprintf('%s must contain at least %d characters.', $path, $schema['minLength']),
            );
        }

        if (is_array($value) && array_is_list($value) && isset($schema['minItems'])) {
            Assert::assertGreaterThanOrEqual(
                (int) $schema['minItems'],
                count($value),
                sprintf('%s must contain at least %d items.', $path, $schema['minItems']),
            );
        }

        if (! is_array($value) || array_is_list($value)) {
            if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
                foreach ($value as $index => $item) {
                    self::assertValid($item, $schema['items'], sprintf('%s[%d]', $path, $index));
                }
            }

            return;
        }

        foreach ($schema['required'] ?? [] as $property) {
            Assert::assertArrayHasKey($property, $value, sprintf('%s.%s is required.', $path, $property));
        }

        foreach ($schema['properties'] ?? [] as $property => $propertySchema) {
            if (array_key_exists($property, $value)) {
                self::assertValid($value[$property], $propertySchema, $path.'.'.$property);
            }
        }
    }

    /**
     * @param  string|list<string>  $expected
     */
    private static function assertType(mixed $value, string|array $expected, string $path): void
    {
        $types = is_array($expected) ? $expected : [$expected];

        foreach ($types as $type) {
            if (self::matchesType($value, $type)) {
                return;
            }
        }

        Assert::fail(sprintf('%s has an invalid JSON type; expected %s.', $path, implode(' or ', $types)));
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }
}
