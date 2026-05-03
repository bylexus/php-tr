<?php

declare(strict_types=1);

namespace ByLexus\DurableTask;

use ByLexus\DurableTask\Exception\ConfigurationException;

/**
 * Normalizes task payload values.
 *
 * Converts arrays and objects into consistent stdClass payload structures that can be serialized reliably.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
final class PayloadNormalizer {
    public static function normalizeRoot(mixed $payload): \stdClass {
        if ($payload === null) {
            return new \stdClass();
        }

        if ($payload instanceof \stdClass) {
            return $payload;
        }

        if (is_array($payload)) {
            return self::arrayToObject($payload);
        }

        if (is_object($payload)) {
            return self::arrayToObject(get_object_vars($payload));
        }

        throw new ConfigurationException(
            sprintf(
                'Root payload must be null, an array, or an object. Received %s.',
                get_debug_type($payload),
            ),
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function arrayToObject(array $payload): \stdClass {
        $object = new \stdClass();

        foreach ($payload as $key => $value) {
            $object->{(string) $key} = $value;
        }

        return $object;
    }
}
