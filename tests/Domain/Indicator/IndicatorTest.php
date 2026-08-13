<?php

declare(strict_types=1);

use MacroRisk\Domain\Indicator\Indicator;
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

$tests = [
    'valid indicator preserves all domain fields' => static function (): void {
        $indicator = new Indicator(
            'policy_rate',
            'financial',
            'Policy Interest Rate',
            'Target for the overnight rate.',
            'percent',
            'bank_of_canada',
            'V39079',
            'daily'
        );

        assertSameValue(
            'policy_rate',
            $indicator->indicatorKey(),
            'Indicator key must be preserved.'
        );

        assertSameValue(
            'financial',
            $indicator->category(),
            'Category must be preserved.'
        );

        assertSameValue(
            'Policy Interest Rate',
            $indicator->title(),
            'Title must be preserved.'
        );

        assertSameValue(
            'Target for the overnight rate.',
            $indicator->description(),
            'Description must be preserved.'
        );

        assertSameValue(
            'percent',
            $indicator->unit(),
            'Unit must be preserved.'
        );

        assertSameValue(
            'bank_of_canada',
            $indicator->sourceKey(),
            'Source key must be preserved.'
        );

        assertSameValue(
            'V39079',
            $indicator->sourceSeriesId(),
            'Source series id must be preserved.'
        );

        assertSameValue(
            'daily',
            $indicator->frequency(),
            'Frequency must be preserved.'
        );

        assertSameValue(
            true,
            $indicator->productionAllowed(),
            'Production allowance must be preserved.'
        );
    },

    'production can be explicitly disabled' => static function (): void {
        $indicator = new Indicator(
            'test_indicator',
            'macro',
            'Test Indicator',
            'Test description.',
            'index',
            'statcan',
            '123456',
            'monthly',
            false
        );

        assertSameValue(
            false,
            $indicator->productionAllowed(),
            'Production allowance must support false.'
        );
    },

    'empty indicator key is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    '',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Empty indicator key must be rejected.'
        );
    },

    'invalid indicator key is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    '../policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Invalid indicator key must be rejected.'
        );
    },

    'invalid category is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'unknown',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Unknown indicator category must be rejected.'
        );
    },

    'empty title is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    '   ',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Empty indicator title must be rejected.'
        );
    },

    'empty description is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    '',
                    'percent',
                    'bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Empty indicator description must be rejected.'
        );
    },

    'empty unit is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    '',
                    'bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Empty indicator unit must be rejected.'
        );
    },

    'invalid source key is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    '../bank_of_canada',
                    'V39079',
                    'daily'
                );
            },
            'Invalid source key must be rejected.'
        );
    },

    'empty source series id is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    '',
                    'daily'
                );
            },
            'Empty source series id must be rejected.'
        );
    },

    'invalid frequency is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    'V39079',
                    'hourly'
                );
            },
            'Unsupported frequency must be rejected.'
        );
    },

    'all supported categories are accepted' => static function (): void {
        foreach (
            [
                'financial',
                'housing',
                'macro',
                'labor',
                'demographic',
                'external',
                'other',
            ] as $category
        ) {
            $indicator = new Indicator(
                'indicator_' . $category,
                $category,
                'Test Indicator',
                'Test description.',
                'index',
                'statcan',
                '123456',
                'monthly'
            );

            assertSameValue(
                $category,
                $indicator->category(),
                'Supported category must be preserved.'
            );
        }
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
            $indicator = new Indicator(
                'test_indicator',
                'macro',
                'Test Indicator',
                'Test description.',
                'index',
                'statcan',
                '123456',
                $frequency
            );

            assertSameValue(
                $frequency,
                $indicator->frequency(),
                'Supported frequency must be preserved.'
            );
        }
    },

    'numeric fields are not accepted as strings implicitly' => static function (): void {
        assertThrows(
            TypeError::class,
            static function (): void {
                new Indicator(
                    'policy_rate',
                    'financial',
                    'Policy Interest Rate',
                    'Description.',
                    'percent',
                    'bank_of_canada',
                    39079,
                    'daily'
                );
            },
            'Source series id must enter Domain as a string.'
        );
    },

    'indicator contains no calculation configuration' => static function (): void {
        $indicator = new Indicator(
            'policy_rate',
            'financial',
            'Policy Interest Rate',
            'Description.',
            'percent',
            'bank_of_canada',
            'V39079',
            'daily'
        );

        assertSameValue(
            true,
            method_exists(
                $indicator,
                'productionAllowed'
            ),
            'Indicator must expose production metadata.'
        );

        assertSameValue(
            false,
            method_exists(
                $indicator,
                'originalWeight'
            ),
            'Weights must not belong to Indicator.'
        );

        assertSameValue(
            false,
            method_exists(
                $indicator,
                'normalizationMethod'
            ),
            'Normalization configuration must not belong to Indicator.'
        );

        assertSameValue(
            false,
            method_exists(
                $indicator,
                'frequencyDiscount'
            ),
            'Frequency discounts must not belong to Indicator.'
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
echo 'ALL INDICATOR DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;