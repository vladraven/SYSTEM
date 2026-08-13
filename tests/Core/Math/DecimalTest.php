<?php

declare(strict_types=1);

use MacroRisk\Core\Math\Decimal;
use DivisionByZeroError;
use InvalidArgumentException;
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

if (!extension_loaded('bcmath')) {
    throw new RuntimeException('BCMath extension is required.');
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
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

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
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
            . 'Actual exception: ' . $exception::class
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
    'raw values preserve scale' => static function (): void {
        $value = Decimal::raw('12.34567891');

        assertSameValue(
            '12.34567891',
            $value->toString(),
            'Raw decimal must preserve eight decimal places.'
        );

        assertSameValue(
            8,
            $value->scale(),
            'Raw decimal must use scale 8.'
        );
    },

    'score values round to score scale' => static function (): void {
        $value = Decimal::score('12.34567');

        assertSameValue(
            '12.3457',
            $value->toString(),
            'Score decimal must round to four decimal places.'
        );

        assertSameValue(
            4,
            $value->scale(),
            'Score decimal must use scale 4.'
        );
    },

    'addition is exact decimal arithmetic' => static function (): void {
        $result = Decimal::raw('1.25000000')
            ->add(Decimal::raw('2.75000000'));

        assertSameValue(
            '4.00000000',
            $result->toString(),
            'Decimal addition must be exact.'
        );
    },

    'subtraction is exact decimal arithmetic' => static function (): void {
        $result = Decimal::raw('5.00000000')
            ->subtract(Decimal::raw('2.12500000'));

        assertSameValue(
            '2.87500000',
            $result->toString(),
            'Decimal subtraction must be exact.'
        );
    },

    'multiplication is exact decimal arithmetic' => static function (): void {
        $result = Decimal::raw('2.50000000')
            ->multiply(Decimal::raw('4.00000000'));

        assertSameValue(
            '10.00000000',
            $result->toString(),
            'Decimal multiplication must be exact.'
        );
    },

    'division is exact at configured scale' => static function (): void {
        $result = Decimal::raw('1.00000000')
            ->divide(Decimal::raw('3.00000000'));

        assertSameValue(
            '0.33333333',
            $result->toString(),
            'Decimal division must use eight decimal places.'
        );
    },

    'division by zero is rejected' => static function (): void {
        assertThrows(
            DivisionByZeroError::class,
            static function (): void {
                Decimal::raw('1.00000000')
                    ->divide(Decimal::zero());
            },
            'Division by zero must throw DivisionByZeroError.'
        );
    },

    'absolute value preserves positive values' => static function (): void {
        $value = Decimal::raw('12.50000000');

        assertSameValue(
            '12.50000000',
            $value->absolute()->toString(),
            'Absolute value of a positive decimal must remain unchanged.'
        );
    },

    'absolute value removes negative sign' => static function (): void {
        $value = Decimal::raw('-12.50000000');

        assertSameValue(
            '12.50000000',
            $value->absolute()->toString(),
            'Absolute value must remove the negative sign.'
        );
    },

    'negation is exact' => static function (): void {
        $positive = Decimal::raw('12.50000000');
        $negative = $positive->negate();

        assertSameValue(
            '-12.50000000',
            $negative->toString(),
            'Negation must preserve magnitude and scale.'
        );

        assertSameValue(
            '12.50000000',
            $negative->negate()->toString(),
            'Double negation must restore the original value.'
        );
    },

    'comparison works across scales' => static function (): void {
        $raw = Decimal::raw('1.25000000');
        $score = Decimal::score('1.2500');

        assertSameValue(
            0,
            $raw->compareTo($score),
            'Equal decimal values with different scales must compare equally.'
        );

        assertTrueValue(
            Decimal::raw('2.00000000')
                ->isGreaterThan(Decimal::score('1.9999')),
            'Greater-than comparison must work across scales.'
        );

        assertTrueValue(
            Decimal::score('1.9999')
                ->isLessThan(Decimal::raw('2.00000000')),
            'Less-than comparison must work across scales.'
        );
    },

    'minimum and maximum are deterministic' => static function (): void {
        $first = Decimal::raw('10.00000000');
        $second = Decimal::raw('20.00000000');

        assertSameValue(
            '10.00000000',
            $first->minimum($second)->toString(),
            'Minimum must return the smaller value.'
        );

        assertSameValue(
            '20.00000000',
            $first->maximum($second)->toString(),
            'Maximum must return the larger value.'
        );
    },

    'round half away from zero for positive values' => static function (): void {
        assertSameValue(
            '1.24',
            Decimal::raw('1.23500000')->rounded(2)->toString(),
            '1.235 must round to 1.24.'
        );

        assertSameValue(
            '1.23',
            Decimal::raw('1.23499999')->rounded(2)->toString(),
            '1.23499999 must round to 1.23.'
        );

        assertSameValue(
            '1.25',
            Decimal::raw('1.24500000')->rounded(2)->toString(),
            '1.245 must round to 1.25.'
        );
    },

    'round half away from zero for negative values' => static function (): void {
        assertSameValue(
            '-1.24',
            Decimal::raw('-1.23500000')->rounded(2)->toString(),
            '-1.235 must round to -1.24.'
        );

        assertSameValue(
            '-1.23',
            Decimal::raw('-1.23499999')->rounded(2)->toString(),
            '-1.23499999 must round to -1.23.'
        );

        assertSameValue(
            '-1.25',
            Decimal::raw('-1.24500000')->rounded(2)->toString(),
            '-1.245 must round to -1.25.'
        );
    },

    'rounding carries into integer part' => static function (): void {
        assertSameValue(
            '2.00',
            Decimal::raw('1.99999999')->rounded(2)->toString(),
            'Rounding must carry into the integer part.'
        );

        assertSameValue(
            '-2.00',
            Decimal::raw('-1.99999999')->rounded(2)->toString(),
            'Negative rounding must carry into the integer part.'
        );
    },

    'rounding to greater scale does not change numeric value' => static function (): void {
        $value = Decimal::score('12.3456');

        $result = $value->rounded(8);

        assertSameValue(
            '12.34560000',
            $result->toString(),
            'Increasing scale must preserve the numeric value.'
        );

        assertSameValue(
            8,
            $result->scale(),
            'Increasing scale must produce the requested scale.'
        );
    },

    'withScale rounds when reducing scale' => static function (): void {
        $value = Decimal::raw('12.34567890');

        $result = $value->withScale(4);

        assertSameValue(
            '12.3457',
            $result->toString(),
            'Reducing scale must use Round Half Away From Zero.'
        );

        assertSameValue(
            4,
            $result->scale(),
            'withScale must use the requested scale.'
        );
    },

    'withScale preserves value when increasing scale' => static function (): void {
        $value = Decimal::score('12.3456');

        $result = $value->withScale(8);

        assertSameValue(
            '12.34560000',
            $result->toString(),
            'Increasing scale must preserve the numeric value.'
        );

        assertSameValue(
            8,
            $result->scale(),
            'withScale must use the requested scale.'
        );
    },

    'empty value is rejected' => static function (): void {
        assertThrows(
            InvalidArgumentException::class,
            static function (): void {
                Decimal::raw('');
            },
            'Empty decimal values must be rejected.'
        );
    },

    'invalid decimal syntax is rejected' => static function (): void {
        $invalidValues = [
            'abc',
            '1,25',
            '1 25',
            '++1',
            '--1',
            '1e5',
        ];

        foreach ($invalidValues as $value) {
            assertThrows(
                InvalidArgumentException::class,
                static function () use ($value): void {
                    Decimal::raw($value);
                },
                "Invalid decimal value must be rejected: {$value}"
            );
        }
    },

    'scale boundaries are enforced' => static function (): void {
        assertThrows(
            InvalidArgumentException::class,
            static function (): void {
                Decimal::of('1', -1);
            },
            'Negative scale must be rejected.'
        );

        assertThrows(
            InvalidArgumentException::class,
            static function (): void {
                Decimal::of('1', 65);
            },
            'Scale greater than 64 must be rejected.'
        );
    },

    'zero and one are correctly represented' => static function (): void {
        assertSameValue(
            '0.00000000',
            Decimal::zero()->toString(),
            'Zero must use the requested default raw scale.'
        );

        assertSameValue(
            '1.00000000',
            Decimal::one()->toString(),
            'One must use the requested default raw scale.'
        );

        assertTrueValue(
            Decimal::zero()->isZero(),
            'Zero must report isZero=true.'
        );

        assertTrueValue(
            Decimal::one()->isPositive(),
            'One must report isPositive=true.'
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
echo 'ALL DECIMAL TESTS PASSED: ' . $passed . PHP_EOL;