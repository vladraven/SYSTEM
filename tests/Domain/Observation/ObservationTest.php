<?php

declare(strict_types=1);

use MacroRisk\Domain\Observation\Observation;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'MacroRisk\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    $file = dirname(__DIR__, 3)
        . '/src/'
        . str_replace('\\', '/', $relative)
        . '.php';

    if (is_file($file)) {
        require $file;
    }
});

function assertSameValue(
    mixed $expected,
    mixed $actual,
    string $message
): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . PHP_EOL
            . 'Expected: ' . var_export($expected, true)
            . PHP_EOL
            . 'Actual: ' . var_export($actual, true)
        );
    }
}

function assertThrows(
    string $exceptionClass,
    callable $callback,
    string $message
): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            $message
            . PHP_EOL
            . 'Expected exception: ' . $exceptionClass
            . PHP_EOL
            . 'Actual exception: ' . $exception::class,
            0,
            $exception
        );
    }

    throw new RuntimeException(
        $message
        . PHP_EOL
        . 'Expected exception: ' . $exceptionClass
        . PHP_EOL
        . 'Actual: no exception'
    );
}

$hash = str_repeat('a', 64);

$tests = [
    'valid observation preserves all domain fields' => static function () use ($hash): void {
        $releaseTime = new DateTimeImmutable(
            '2026-08-12T00:00:00Z'
        );

        $observation = new Observation(
            '2026-08-12',
            '2.75000000',
            $releaseTime,
            'valid',
            $hash
        );

        assertSameValue(
            '2026-08-12',
            $observation->referencePeriod(),
            'Reference period must be preserved.'
        );

        assertSameValue(
            '2.75000000',
            $observation->value(),
            'Decimal value must be preserved exactly.'
        );

        assertSameValue(
            $releaseTime,
            $observation->releaseTime(),
            'Release time must be preserved.'
        );

        assertSameValue(
            'valid',
            $observation->status(),
            'Status must be preserved.'
        );

        assertSameValue(
            $hash,
            $observation->rawHash(),
            'Raw hash must be preserved.'
        );

        assertSameValue(
            true,
            $observation->isValid(),
            'Valid observation must report valid status.'
        );
    },

    'large decimal value is preserved without float conversion' => static function () use ($hash): void {
        $value = '12345678901234567890.123456789012345678';

        $observation = new Observation(
            '2026-08-12',
            $value,
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'valid',
            $hash
        );

        assertSameValue(
            $value,
            $observation->value(),
            'Large decimal values must remain exact strings.'
        );
    },

    'zero decimal value is accepted' => static function () use ($hash): void {
        $observation = new Observation(
            '2026-08-12',
            '0.00000000',
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'valid',
            $hash
        );

        assertSameValue(
            '0.00000000',
            $observation->value(),
            'Zero must remain a decimal string.'
        );
    },

    'negative decimal value is accepted' => static function () use ($hash): void {
        $observation = new Observation(
            '2026-08-12',
            '-12.50000000',
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'valid',
            $hash
        );

        assertSameValue(
            '-12.50000000',
            $observation->value(),
            'Negative decimal values must be accepted.'
        );
    },

    'integer typed value is rejected' => static function () use ($hash): void {
        assertThrows(
            TypeError::class,
            static function () use ($hash): void {
                new Observation(
                    '2026-08-12',
                    10,
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'valid',
                    $hash
                );
            },
            'Integer observation values must not enter Domain.'
        );
    },

    'float typed value is rejected' => static function () use ($hash): void {
        assertThrows(
            TypeError::class,
            static function () use ($hash): void {
                new Observation(
                    '2026-08-12',
                    10.25,
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'valid',
                    $hash
                );
            },
            'Float observation values must not enter Domain.'
        );
    },

    'invalid decimal string is rejected' => static function () use ($hash): void {
        assertThrows(
            RuntimeException::class,
            static function () use ($hash): void {
                new Observation(
                    '2026-08-12',
                    '1.2.3',
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'valid',
                    $hash
                );
            },
            'Invalid decimal strings must be rejected.'
        );
    },

    'empty decimal string is rejected' => static function () use ($hash): void {
        assertThrows(
            RuntimeException::class,
            static function () use ($hash): void {
                new Observation(
                    '2026-08-12',
                    '',
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'valid',
                    $hash
                );
            },
            'Empty observation values must be rejected.'
        );
    },

    'invalid reference period is rejected' => static function () use ($hash): void {
        assertThrows(
            RuntimeException::class,
            static function () use ($hash): void {
                new Observation(
                    '2026-02-30',
                    '1.00000000',
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'valid',
                    $hash
                );
            },
            'Invalid reference periods must be rejected.'
        );
    },

    'invalid observation status is rejected' => static function () use ($hash): void {
        assertThrows(
            RuntimeException::class,
            static function () use ($hash): void {
                new Observation(
                    '2026-08-12',
                    '1.00000000',
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'unknown',
                    $hash
                );
            },
            'Unknown observation statuses must be rejected.'
        );
    },

    'invalid raw hash is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Observation(
                    '2026-08-12',
                    '1.00000000',
                    new DateTimeImmutable(
                        '2026-08-12T00:00:00Z'
                    ),
                    'valid',
                    'not-a-hash'
                );
            },
            'Raw hash must be a SHA-256 hexadecimal string.'
        );
    },

    'non valid observation is not valid' => static function () use ($hash): void {
        $observation = new Observation(
            '2026-08-12',
            '1.00000000',
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'revised',
            $hash
        );

        assertSameValue(
            false,
            $observation->isValid(),
            'Non-valid observation must not report valid status.'
        );
    },
];

$passed = 0;

foreach ($tests as $name => $test) {
    $test();
    $passed++;

    echo '[OK] ' . $name . PHP_EOL;
}

echo PHP_EOL;
echo 'ALL OBSERVATION DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;