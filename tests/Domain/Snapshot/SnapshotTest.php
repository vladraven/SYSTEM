<?php

declare(strict_types=1);

use MacroRisk\Domain\Observation\Observation;
use MacroRisk\Domain\Snapshot\Snapshot;
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

$observationOne = new Observation(
    '2026-08-10',
    '2.50000000',
    new DateTimeImmutable(
        '2026-08-10T08:30:00Z'
    ),
    'valid',
    str_repeat('a', 64)
);

$observationTwo = new Observation(
    '2026-08-11',
    '2.75000000',
    new DateTimeImmutable(
        '2026-08-11T08:30:00Z'
    ),
    'valid',
    str_repeat('b', 64)
);

$tests = [
    'valid snapshot preserves identity and vintage' => static function () use (
        $observationOne,
        $observationTwo
    ): void {
        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12',
            [
                'policy_rate' => $observationOne,
                'unemployment_rate' => $observationTwo,
            ]
        );

        assertSameValue(
            'snapshot-2026-08-12',
            $snapshot->snapshotId(),
            'Snapshot id must be preserved.'
        );

        assertSameValue(
            '2026-08-12',
            $snapshot->vintageDate(),
            'Vintage date must be preserved.'
        );

        assertSameValue(
            2,
            $snapshot->observationCount(),
            'Observation count must be preserved.'
        );

        assertSameValue(
            false,
            $snapshot->isEmpty(),
            'Snapshot with observations must not be empty.'
        );
    },

    'observations are addressable by indicator key' => static function () use (
        $observationOne,
        $observationTwo
    ): void {
        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12',
            [
                'policy_rate' => $observationOne,
                'unemployment_rate' => $observationTwo,
            ]
        );

        assertSameValue(
            true,
            $snapshot->hasIndicator('policy_rate'),
            'Existing indicator must be found.'
        );

        assertSameValue(
            $observationOne,
            $snapshot->observationFor('policy_rate'),
            'Observation must be returned by indicator key.'
        );

        assertSameValue(
            false,
            $snapshot->hasIndicator('missing_indicator'),
            'Missing indicator must not be found.'
        );

        assertSameValue(
            null,
            $snapshot->observationFor('missing_indicator'),
            'Missing observation must return null.'
        );
    },

    'empty snapshot is accepted' => static function (): void {
        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12'
        );

        assertSameValue(
            0,
            $snapshot->observationCount(),
            'Empty snapshot must have zero observations.'
        );

        assertSameValue(
            true,
            $snapshot->isEmpty(),
            'Empty snapshot must report empty.'
        );
    },

    'observation collection is copied on construction' => static function () use (
        $observationOne
    ): void {
        $observations = [
            'policy_rate' => $observationOne,
        ];

        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12',
            $observations
        );

        $observations['unemployment_rate'] = new Observation(
            '2026-08-11',
            '5.00000000',
            new DateTimeImmutable(
                '2026-08-11T08:30:00Z'
            ),
            'valid',
            str_repeat('c', 64)
        );

        assertSameValue(
            1,
            $snapshot->observationCount(),
            'Mutating source collection must not mutate Snapshot.'
        );
    },

    'returned observation collection does not replace internal collection' => static function () use (
        $observationOne
    ): void {
        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12',
            [
                'policy_rate' => $observationOne,
            ]
        );

        $observations = $snapshot->observations();

        $observations['unemployment_rate'] = new Observation(
            '2026-08-11',
            '5.00000000',
            new DateTimeImmutable(
                '2026-08-11T08:30:00Z'
            ),
            'valid',
            str_repeat('c', 64)
        );

        assertSameValue(
            1,
            $snapshot->observationCount(),
            'Mutating returned collection must not mutate Snapshot.'
        );
    },

    'raw arrays are rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Snapshot(
                    'snapshot-2026-08-12',
                    '2026-08-12',
                    [
                        'policy_rate' => [
                            'reference_period' => '2026-08-10',
                            'value' => '2.50000000',
                        ],
                    ]
                );
            },
            'Snapshot must accept Observation objects, not raw arrays.'
        );
    },

    'non Observation object is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Snapshot(
                    'snapshot-2026-08-12',
                    '2026-08-12',
                    [
                        'policy_rate' => new stdClass(),
                    ]
                );
            },
            'Snapshot must reject non-Observation objects.'
        );
    },

    'observation released after vintage date is rejected' => static function (): void {
        $lateObservation = new Observation(
            '2026-08-12',
            '2.75000000',
            new DateTimeImmutable(
                '2026-08-13T08:30:00Z'
            ),
            'valid',
            str_repeat('d', 64)
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($lateObservation): void {
                new Snapshot(
                    'snapshot-2026-08-12',
                    '2026-08-12',
                    [
                        'policy_rate' => $lateObservation,
                    ]
                );
            },
            'Observation released after vintage date must be rejected.'
        );
    },

    'observation released on vintage date is accepted' => static function (): void {
        $sameDayObservation = new Observation(
            '2026-08-12',
            '2.75000000',
            new DateTimeImmutable(
                '2026-08-12T23:59:59Z'
            ),
            'valid',
            str_repeat('e', 64)
        );

        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12',
            [
                'policy_rate' => $sameDayObservation,
            ]
        );

        assertSameValue(
            $sameDayObservation,
            $snapshot->observationFor('policy_rate'),
            'Observation released on vintage date must be accepted.'
        );
    },

    'empty snapshot id is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Snapshot(
                    '',
                    '2026-08-12'
                );
            },
            'Empty snapshot id must be rejected.'
        );
    },

    'invalid snapshot id is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Snapshot(
                    '../snapshot',
                    '2026-08-12'
                );
            },
            'Invalid snapshot id must be rejected.'
        );
    },

    'invalid vintage date is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Snapshot(
                    'snapshot-2026-08-12',
                    '2026-02-30'
                );
            },
            'Invalid vintage date must be rejected.'
        );
    },

    'invalid observation key is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function () use ($observationOne): void {
                new Snapshot(
                    'snapshot-2026-08-12',
                    '2026-08-12',
                    [
                        '../policy_rate' => $observationOne,
                    ]
                );
            },
            'Invalid observation key must be rejected.'
        );
    },

    'observation decimal remains exact inside snapshot' => static function (): void {
        $value = '12345678901234567890.123456789012345678';

        $observation = new Observation(
            '2026-08-10',
            $value,
            new DateTimeImmutable(
                '2026-08-10T08:30:00Z'
            ),
            'valid',
            str_repeat('f', 64)
        );

        $snapshot = new Snapshot(
            'snapshot-2026-08-12',
            '2026-08-12',
            [
                'large_value' => $observation,
            ]
        );

        assertSameValue(
            $value,
            $snapshot
                ->observationFor('large_value')
                ?->value(),
            'Snapshot must not alter decimal observation values.'
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
echo 'ALL SNAPSHOT DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;