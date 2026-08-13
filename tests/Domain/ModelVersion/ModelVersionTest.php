<?php

declare(strict_types=1);

use MacroRisk\Domain\ModelVersion\ModelVersion;
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

function validModelVersion(): ModelVersion
{
    return new ModelVersion(
        '2026.1',
        'Canonical MacroRisk deterministic risk model.'
    );
}

$tests = [
    'valid model version preserves all parameters' => static function (): void {
        $modelVersion = validModelVersion();

        assertSameValue(
            '2026.1',
            $modelVersion->version(),
            'Model version must be preserved.'
        );

        assertSameValue(
            'Canonical MacroRisk deterministic risk model.',
            $modelVersion->description(),
            'Model version description must be preserved.'
        );
    },

    'empty model version is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new ModelVersion(
                    '',
                    'Canonical MacroRisk deterministic risk model.'
                );
            },
            'Empty model version must be rejected.'
        );
    },

    'invalid model version is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new ModelVersion(
                    '2026/1',
                    'Canonical MacroRisk deterministic risk model.'
                );
            },
            'Model version containing invalid characters must be rejected.'
        );
    },

    'model version with whitespace is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new ModelVersion(
                    ' 2026.1',
                    'Canonical MacroRisk deterministic risk model.'
                );
            },
            'Model version with leading whitespace must be rejected.'
        );
    },

    'model version with spaces is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new ModelVersion(
                    '2026 1',
                    'Canonical MacroRisk deterministic risk model.'
                );
            },
            'Model version containing spaces must be rejected.'
        );
    },

    'empty description is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new ModelVersion(
                    '2026.1',
                    ''
                );
            },
            'Empty model version description must be rejected.'
        );
    },

    'whitespace-only description is rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new ModelVersion(
                    '2026.1',
                    '   '
                );
            },
            'Whitespace-only model version description must be rejected.'
        );
    },

    'decimal version identifiers are preserved exactly' => static function (): void {
        $modelVersion = new ModelVersion(
            '2026.1.0.0001',
            'Deterministic model revision.'
        );

        assertSameValue(
            '2026.1.0.0001',
            $modelVersion->version(),
            'Model version identifier must be preserved exactly.'
        );
    },

    'description preserves exact text' => static function (): void {
        $description = 'Model uses explicit observations, deterministic transformations and configured normalization rules.';

        $modelVersion = new ModelVersion(
            '2026.2',
            $description
        );

        assertSameValue(
            $description,
            $modelVersion->description(),
            'Model version description must be preserved exactly.'
        );
    },

    'model version exposes no calculation result state' => static function (): void {
        $modelVersion = validModelVersion();

        assertSameValue(
            false,
            method_exists(
                $modelVersion,
                'riskScore'
            ),
            'ModelVersion must not contain calculation results.'
        );

        assertSameValue(
            false,
            method_exists(
                $modelVersion,
                'riskBand'
            ),
            'ModelVersion must not contain risk band results.'
        );

        assertSameValue(
            false,
            method_exists(
                $modelVersion,
                'calculationStatus'
            ),
            'ModelVersion must not contain calculation status.'
        );
    },

    'model version has no infrastructure dependencies' => static function (): void {
        $reflection = new ReflectionClass(ModelVersion::class);

        assertSameValue(
            'MacroRisk\\Domain\\ModelVersion',
            $reflection->getNamespaceName(),
            'ModelVersion must remain inside the Domain namespace.'
        );

        assertSameValue(
            false,
            $reflection->implementsInterface('JsonSerializable'),
            'ModelVersion must not depend on JSON serialization.'
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
echo 'ALL MODEL VERSION DOMAIN TESTS PASSED: ' . $passed . PHP_EOL;