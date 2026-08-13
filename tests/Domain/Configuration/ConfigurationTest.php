<?php

declare(strict_types=1);

use MacroRisk\Domain\Configuration\Configuration;
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

function validConfiguration(): Configuration
{
    return new Configuration(
        '2026.1',
        [
            'policy_rate' => '40.0000',
            'unemployment_rate' => '35.0000',
            'housing_starts' => '25.0000',
        ],
        [
            'policy_rate' => [
                'method' => 'threshold_linear',
                'direction' => 'higher_is_riskier',
                'low' => '1.0000',
                'high' => '5.0000',
            ],
            'unemployment_rate' => [
                'method' => 'distance_from_target_is_riskier',
                'direction' => 'higher_is_riskier',
                'target' => '5.0000',
                'max_deviation' => '10.0000',
            ],
            'housing_starts' => [
                'method' => 'outside_band_is_riskier',
                'direction' => 'lower_is_riskier',
                'outside_min' => '50000.0000',
                'safe_min' => '100000.0000',
                'safe_max' => '150000.0000',
                'outside_max' => '250000.0000',
            ],
        ],
        [
            'daily' => '1.0000',
            'weekly' => '0.9500',
            'monthly' => '0.9000',
            'quarterly' => '0.8500',
            'annual' => '0.7500',
            'irregular' => '0.5000',
        ],
        '60.0000',
        3,
        [
            'very_low' => '20.0000',
            'low' => '40.0000',
            'moderate' => '60.0000',
            'high' => '80.0000',
            'severe' => '100.0000',
        ]
    );
}

$tests = [
    'valid configuration preserves all model parameters' => static function (): void {
        $configuration = validConfiguration();

        assertSameValue(
            '2026.1',
            $configuration->configurationVersion(),
            'Configuration version must be preserved.'
        );

        assertSameValue(
            '60.0000',
            $configuration->coverageThreshold(),
            'Coverage threshold must be preserved.'
        );

        assertSameValue(
            3,
            $configuration->minimumEligibleIndicators(),
            'Minimum eligible indicator count must be preserved.'
        );

        assertSameValue(
            '40.0000',
            $configuration->originalWeightFor('policy_rate'),
            'Original weight must be preserved.'
        );

        assertSameValue(
            'threshold_linear',
            $configuration
                ->normalizationFor('policy_rate')['method'],
            'Normalization method must be preserved.'
        );

        assertSameValue(
            '0.9000',
            $configuration->frequencyDiscountFor('monthly'),
            'Frequency discount must be preserved.'
        );

        assertSameValue(
            '80.0000',
            $configuration->riskBandThresholdFor('high'),
            'Risk band threshold must be preserved.'
        );
    },

    'original weights must sum exactly to 100' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    [
                        'policy_rate' => '40.0000',
                        'unemployment_rate' => '35.0000',
                        'housing_starts' => '24.9999',
                    ],
                    [],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Weights not summing exactly to 100 must be rejected.'
        );
    },

    'empty original weights are rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    [],
                    [],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Empty original weights must be rejected.'
        );
    },

    'negative original weight is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    [
                        'policy_rate' => '101.0000',
                        'unemployment_rate' => '-1.0000',
                    ],
                    [],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Negative original weights must be rejected.'
        );
    },

    'float original weight is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    [
                        'policy_rate' => 100.0,
                    ],
                    [],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Float weights must not enter Domain configuration.'
        );
    },

    'coverage threshold outside range is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [],
                    [],
                    '100.0001',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Coverage threshold above 100 must be rejected.'
        );
    },

    'minimum eligible indicator count must be positive' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [],
                    [],
                    '60.0000',
                    0,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Minimum eligible indicators must be positive.'
        );
    },

    'threshold normalization requires low and high' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [
                        'policy_rate' => [
                            'method' => 'threshold_linear',
                            'direction' => 'higher_is_riskier',
                        ],
                    ],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Threshold normalization requires low and high.'
        );
    },

    'distance normalization requires positive max deviation' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [
                        'policy_rate' => [
                            'method' => 'distance_from_target_is_riskier',
                            'direction' => 'higher_is_riskier',
                            'target' => '5.0000',
                            'max_deviation' => '0.0000',
                        ],
                    ],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Distance normalization requires positive max deviation.'
        );
    },

    'outside band requires strict boundaries' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [
                        'policy_rate' => [
                            'method' => 'outside_band_is_riskier',
                            'direction' => 'higher_is_riskier',
                            'outside_min' => '100.0000',
                            'safe_min' => '100.0000',
                            'safe_max' => '150.0000',
                            'outside_max' => '250.0000',
                        ],
                    ],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Outside-band boundaries must be strictly ordered.'
        );
    },

    'frequency discounts must remain between zero and one' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [],
                    [
                        'monthly' => '1.0001',
                    ],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Frequency discounts above one must be rejected.'
        );
    },

    'all five risk bands are required' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '60.0000',
                        'high' => '80.0000',
                    ]
                );
            },
            'All five risk band thresholds are required.'
        );
    },

    'risk band thresholds must be strictly increasing' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new Configuration(
                    '2026.1',
                    ['policy_rate' => '100.0000'],
                    [],
                    [],
                    '60.0000',
                    3,
                    [
                        'very_low' => '20.0000',
                        'low' => '40.0000',
                        'moderate' => '40.0000',
                        'high' => '80.0000',
                        'severe' => '100.0000',
                    ]
                );
            },
            'Risk band thresholds must be strictly increasing.'
        );
    },

    'decimal strings are preserved exactly' => static function (): void {
        $configuration = new Configuration(
            '2026.1',
            [
                'policy_rate' => '40.000000000000000001',
                'unemployment_rate' => '35.000000000000000001',
                'housing_starts' => '24.999999999999999998',
            ],
            [],
            [
                'monthly' => '0.900000000000000001',
            ],
            '60.000000000000000001',
            3,
            [
                'very_low' => '20.000000000000000001',
                'low' => '40.000000000000000001',
                'moderate' => '60.000000000000000001',
                'high' => '80.000000000000000001',
                'severe' => '100.000000000000000001',
            ]
        );

        assertSameValue(
            '40.000000000000000001',
            $configuration->originalWeightFor('policy_rate'),
            'Original decimal string must be preserved.'
        );

        assertSameValue(
            '0.900000000000000001',
            $configuration->frequencyDiscountFor('monthly'),
            'Frequency decimal string must be preserved.'
        );
    },

    'configuration exposes no calculation result state' => static function (): void {
        $configuration = validConfiguration();

        assertSameValue(
            false,
            method_exists(
                $configuration,
                'riskScore'
            ),
            'Configuration must not contain calculation results.'
        );

        assertSameValue(
            false,
            method_exists(
                $configuration,
                'calculationStatus'
            ),
            'Configuration must not contain calculation status.'
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
echo 'ALL CONFIGURATION DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;