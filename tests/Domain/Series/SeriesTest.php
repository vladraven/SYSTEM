<?php

declare(strict_types=1);

use MacroRisk\Domain\Observation\Observation;
use MacroRisk\Domain\Series\Series;
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

$observationOne = new Observation(
    '2026-08-11',
    '2.50000000',
    new DateTimeImmutable(
        '2026-08-11T00:00:00Z'
    ),
    'valid',
    $hash
);

$observationTwo = new Observation(
    '2026-08-12',
    '2.75000000',
    new DateTimeImmutable(
        '2026-08-12T00:00:00Z'
    ),
    'valid',
    $hash
);

$tests = [
    'valid series preserves schema and metadata' => static function () use (
        $observationOne,
        $observationTwo
    ): void {
        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            [
                $observationOne,
                $observationTwo,
            ]
        );

        assertSameValue(
            1,
            $series->schemaVersion(),
            'Series schema version must be 1.'
        );

        assertSameValue(
            'policy_rate',
            $series->indicatorKey(),
            'Indicator key must be preserved.'
        );

        assertSameValue(
            'bank_of_canada',
            $series->sourceKey(),
            'Source key must be preserved.'
        );

        assertSameValue(
            'V39079',
            $series->sourceSeriesId(),
            'Source series id must be preserved.'
        );

        assertSameValue(
            'Policy Interest Rate',
            $series->title(),
            'Series title must be preserved.'
        );

        assertSameValue(
            'daily',
            $series->frequency(),
            'Series frequency must be preserved.'
        );

        assertSameValue(
            'percent',
            $series->unit(),
            'Series unit must be preserved.'
        );

        assertSameValue(
            2,
            $series->observationCount(),
            'Observation count must be preserved.'
        );

        assertSameValue(
            false,
            $series->isEmpty(),
            'Series with observations must not be empty.'
        );
    },

    'observations remain Observation objects' => static function () use (
        $observationOne,
        $observationTwo
    ): void {
        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            [
                $observationOne,
                $observationTwo,
            ]
        );

        $observations = $series->observations();

        assertSameValue(
            $observationOne,
            $observations[0],
            'First observation must be preserved.'
        );

        assertSameValue(
            $observationTwo,
            $observations[1],
            'Second observation must be preserved.'
        );
    },

    'observation collection is copied on construction' => static function () use (
        $observationOne
    ): void {
        $observations = [
            $observationOne,
        ];

        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            $observations
        );

        $observations[] = new Observation(
            '2026-08-12',
            '2.75000000',
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'valid',
            str_repeat('b', 64)
        );

        assertSameValue(
            1,
            $series->observationCount(),
            'Mutating the source array must not mutate Series.'
        );
    },

    'returned observation collection does not replace internal collection' => static function () use (
        $observationOne
    ): void {
        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            [
                $observationOne,
            ]
        );

        $observations = $series->observations();

        $observations[] = new Observation(
            '2026-08-12',
            '2.75000000',
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'valid',
            str_repeat('b', 64)
        );

        assertSameValue(
            1,
            $series->observationCount(),
            'Mutating returned array must not mutate Series.'
        );
    },

    'empty observation collection is accepted' => static function (): void {
        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent'
        );

        assertSameValue(
            0,
            $series->observationCount(),
            'Empty series must have zero observations.'
        );

        assertSameValue(
            true,
            $series->isEmpty(),
            'Empty series must report empty.'
        );
    },

    'reference period lookup finds existing observation' => static function () use (
        $observationOne,
        $observationTwo
    ): void {
        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            [
                $observationOne,
                $observationTwo,
            ]
        );

        assertSameValue(
            true,
            $series->hasReferencePeriod('2026-08-12'),
            'Existing reference period must be found.'
        );
    },

    'reference period lookup rejects absent observation' => static function () use (
        $observationOne,
        $observationTwo
    ): void {
        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            [
                $observationOne,
                $observationTwo,
            ]
        );

        assertSameValue(
            false,
            $series->hasReferencePeriod('2026-08-13'),
            'Absent reference period must not be found.'
        );
    },

    'empty indicator key is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    '',
                    'bank_of_canada',
                    'V39079',
                    'Policy Interest Rate',
                    'daily',
                    'percent'
                );
            },
            'Empty indicator key must be rejected.'
        );
    },

    'invalid source key is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    '../bank_of_canada',
                    'V39079',
                    'Policy Interest Rate',
                    'daily',
                    'percent'
                );
            },
            'Invalid source key must be rejected.'
        );
    },

    'empty source series id is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    'bank_of_canada',
                    '',
                    'Policy Interest Rate',
                    'daily',
                    'percent'
                );
            },
            'Empty source series id must be rejected.'
        );
    },

    'empty title is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    'bank_of_canada',
                    'V39079',
                    '   ',
                    'daily',
                    'percent'
                );
            },
            'Empty title must be rejected.'
        );
    },

    'invalid frequency is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    'bank_of_canada',
                    'V39079',
                    'Policy Interest Rate',
                    'hourly',
                    'percent'
                );
            },
            'Unsupported frequency must be rejected.'
        );
    },

    'empty unit is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    'bank_of_canada',
                    'V39079',
                    'Policy Interest Rate',
                    'daily',
                    ''
                );
            },
            'Empty unit must be rejected.'
        );
    },

    'non Observation item is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    'bank_of_canada',
                    'V39079',
                    'Policy Interest Rate',
                    'daily',
                    'percent',
                    [
                        [
                            'reference_period' => '2026-08-12',
                            'value' => '2.75000000',
                        ],
                    ]
                );
            },
            'Series must reject raw arrays instead of domain observations.'
        );
    },

    'invalid observation object type is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Series(
                    'policy_rate',
                    'bank_of_canada',
                    'V39079',
                    'Policy Interest Rate',
                    'daily',
                    'percent',
                    [
                        new stdClass(),
                    ]
                );
            },
            'Series must reject non-Observation objects.'
        );
    },

    'all supported frequencies are accepted' => static function (): void {
        foreach (
            [
                'daily',
                'weekly',
                'monthly',
                'quarterly',
                'annual',
                'irregular',
            ] as $frequency
        ) {
            $series = new Series(
                'policy_rate',
                'bank_of_canada',
                'V39079',
                'Policy Interest Rate',
                $frequency,
                'percent'
            );

            assertSameValue(
                $frequency,
                $series->frequency(),
                'Supported frequency must be preserved.'
            );
        }
    },

    'decimal observation values remain exact through Series' => static function (): void {
        $value = '12345678901234567890.123456789012345678';

        $observation = new Observation(
            '2026-08-12',
            $value,
            new DateTimeImmutable(
                '2026-08-12T00:00:00Z'
            ),
            'valid',
            str_repeat('c', 64)
        );

        $series = new Series(
            'policy_rate',
            'bank_of_canada',
            'V39079',
            'Policy Interest Rate',
            'daily',
            'percent',
            [
                $observation,
            ]
        );

        assertSameValue(
            $value,
            $series->observations()[0]->value(),
            'Series must not alter Observation decimal values.'
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
echo 'ALL SERIES DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;