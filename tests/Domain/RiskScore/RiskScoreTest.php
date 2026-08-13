<?php

declare(strict_types=1);

use MacroRisk\Domain\Risk\RiskScore;
use RuntimeException;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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

function validRiskScore(): RiskScore
{
    return new RiskScore(
        '2026.1',
        '2026.1',
        '2026-08-13',
        'ok',
        '57.25000000',
        'moderate',
        '85.00000000',
        [
            'policy_rate' => '45.00000000',
            'unemployment_rate' => '35.00000000',
            'housing_starts' => '20.00000000',
        ],
        [
            'policy_rate' => '70.00000000',
            'unemployment_rate' => '55.00000000',
            'housing_starts' => '40.00000000',
        ],
        [
            'policy_rate' => '31.50000000',
            'unemployment_rate' => '19.25000000',
            'housing_starts' => '8.00000000',
        ],
        str_repeat('a', 64)
    );
}

$tests = [
    'valid successful result preserves all fields' => static function (): void {
        $result = validRiskScore();

        assertSameValue(
            '2026.1',
            $result->modelVersion(),
            'Model version must be preserved.'
        );

        assertSameValue(
            '2026.1',
            $result->configurationVersion(),
            'Configuration version must be preserved.'
        );

        assertSameValue(
            '2026-08-13',
            $result->vintageDate(),
            'Vintage date must be preserved.'
        );

        assertSameValue(
            'ok',
            $result->status(),
            'Calculation status must be preserved.'
        );

        assertSameValue(
            '57.25000000',
            $result->riskScore(),
            'Risk score must be preserved exactly.'
        );

        assertSameValue(
            'moderate',
            $result->riskBand(),
            'Risk band must be preserved.'
        );

        assertSameValue(
            '85.00000000',
            $result->coverageRatio(),
            'Coverage ratio must be preserved exactly.'
        );

        assertSameValue(
            '45.00000000',
            $result->effectiveWeightFor('policy_rate'),
            'Effective weight must be preserved.'
        );

        assertSameValue(
            '55.00000000',
            $result->normalizedScoreFor('unemployment_rate'),
            'Normalized score must be preserved.'
        );

        assertSameValue(
            '8.00000000',
            $result->contributionFor('housing_starts'),
            'Contribution must be preserved.'
        );

        assertSameValue(
            str_repeat('a', 64),
            $result->calculationHash(),
            'Calculation hash must be preserved.'
        );
    },

    'successful result reports a risk result' => static function (): void {
        $result = validRiskScore();

        assertSameValue(
            true,
            $result->isSuccessful(),
            'Successful result must report successful status.'
        );

        assertSameValue(
            true,
            $result->hasRiskResult(),
            'Successful result must contain risk result.'
        );
    },

    'non ok result requires null risk score and band' => static function (): void {
        $result = new RiskScore(
            '2026.1',
            '2026.1',
            '2026-08-13',
            'low_coverage',
            null,
            null,
            '45.0000',
            [],
            [],
            [],
            str_repeat('b', 64)
        );

        assertSameValue(
            null,
            $result->riskScore(),
            'Non-ok result must have null risk score.'
        );

        assertSameValue(
            null,
            $result->riskBand(),
            'Non-ok result must have null risk band.'
        );

        assertSameValue(
            false,
            $result->isSuccessful(),
            'Non-ok result must not report successful status.'
        );

        assertSameValue(
            false,
            $result->hasRiskResult(),
            'Non-ok result must not contain risk result.'
        );
    },

    'all non ok statuses are accepted' => static function (): void {
        foreach (
            [
                'insufficient_data',
                'low_coverage',
                'required_indicator_missing',
                'missing_no_historical_data',
            ] as $status
        ) {
            $result = new RiskScore(
                '2026.1',
                '2026.1',
                '2026-08-13',
                $status,
                null,
                null,
                '50.0000',
                [],
                [],
                [],
                str_repeat('c', 64)
            );

            assertSameValue(
                $status,
                $result->status(),
                "Status {$status} must be accepted."
            );
        }
    },

    'invalid calculation status is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'failed',
                    null,
                    null,
                    '50.0000',
                    [],
                    [],
                    [],
                    str_repeat('d', 64)
                );
            },
            'Unknown calculation status must be rejected.'
        );
    },

    'successful result requires risk score' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    null,
                    'moderate',
                    '80.0000',
                    [],
                    [],
                    [],
                    str_repeat('e', 64)
                );
            },
            'Successful result without risk score must be rejected.'
        );
    },

    'successful result requires risk band' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    '50.0000',
                    null,
                    '80.0000',
                    [],
                    [],
                    [],
                    str_repeat('f', 64)
                );
            },
            'Successful result without risk band must be rejected.'
        );
    },

    'risk score outside zero to one hundred is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    '100.00000001',
                    'severe',
                    '100.0000',
                    [],
                    [],
                    [],
                    str_repeat('1', 64)
                );
            },
            'Risk score above 100 must be rejected.'
        );
    },

    'invalid risk band is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    '50.0000',
                    'critical',
                    '80.0000',
                    [],
                    [],
                    [],
                    str_repeat('2', 64)
                );
            },
            'Unknown risk band must be rejected.'
        );
    },

    'coverage below zero is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'low_coverage',
                    null,
                    null,
                    '-1.0000',
                    [],
                    [],
                    [],
                    str_repeat('3', 64)
                );
            },
            'Negative coverage must be rejected.'
        );
    },

    'coverage above one hundred is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'low_coverage',
                    null,
                    null,
                    '100.0001',
                    [],
                    [],
                    [],
                    str_repeat('4', 64)
                );
            },
            'Coverage above 100 must be rejected.'
        );
    },

    'float score is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    50.0,
                    'moderate',
                    '80.0000',
                    [],
                    [],
                    [],
                    str_repeat('5', 64)
                );
            },
            'Risk score must be a decimal string.'
        );
    },

    'invalid calculation hash is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'low_coverage',
                    null,
                    null,
                    '50.0000',
                    [],
                    [],
                    [],
                    'invalid-hash'
                );
            },
            'Calculation hash must be a SHA-256 hexadecimal string.'
        );
    },

    'invalid vintage date is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-02-30',
                    'low_coverage',
                    null,
                    null,
                    '50.0000',
                    [],
                    [],
                    [],
                    str_repeat('6', 64)
                );
            },
            'Invalid vintage date must be rejected.'
        );
    },

    'effective weights must use decimal strings' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    '50.0000',
                    'moderate',
                    '80.0000',
                    [
                        'policy_rate' => 50.0,
                    ],
                    [
                        'policy_rate' => '50.0000',
                    ],
                    [
                        'policy_rate' => '25.0000',
                    ],
                    str_repeat('7', 64)
                );
            },
            'Effective weights must use decimal strings.'
        );
    },

    'negative effective weight is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    '50.0000',
                    'moderate',
                    '80.0000',
                    [
                        'policy_rate' => '-50.0000',
                    ],
                    [
                        'policy_rate' => '50.0000',
                    ],
                    [
                        'policy_rate' => '25.0000',
                    ],
                    str_repeat('8', 64)
                );
            },
            'Negative effective weights must be rejected.'
        );
    },

    'map keys must match' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new RiskScore(
                    '2026.1',
                    '2026.1',
                    '2026-08-13',
                    'ok',
                    '50.0000',
                    'moderate',
                    '80.0000',
                    [
                        'policy_rate' => '50.0000',
                    ],
                    [
                        'unemployment_rate' => '50.0000',
                    ],
                    [
                        'policy_rate' => '25.0000',
                    ],
                    str_repeat('9', 64)
                );
            },
            'Weight, score and contribution keys must match.'
        );
    },

    'high precision decimal values are preserved exactly' => static function (): void {
        $result = new RiskScore(
            '2026.1',
            '2026.1',
            '2026-08-13',
            'ok',
            '57.250000000000000001',
            'moderate',
            '85.000000000000000001',
            [
                'policy_rate' => '45.000000000000000001',
            ],
            [
                'policy_rate' => '70.000000000000000001',
            ],
            [
                'policy_rate' => '31.500000000000000001',
            ],
            str_repeat('a', 64)
        );

        assertSameValue(
            '57.250000000000000001',
            $result->riskScore(),
            'Risk score precision must be preserved.'
        );

        assertSameValue(
            '85.000000000000000001',
            $result->coverageRatio(),
            'Coverage precision must be preserved.'
        );

        assertSameValue(
            '45.000000000000000001',
            $result->effectiveWeightFor('policy_rate'),
            'Effective weight precision must be preserved.'
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
echo 'ALL RISK SCORE DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;