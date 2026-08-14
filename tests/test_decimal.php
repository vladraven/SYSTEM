<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MacroRisk\Core\Math\Decimal;

$tests = [
    'round half away from zero is deterministic' => static function (): void {
        assertSameValue('1.24', Decimal::raw('1.23500000')->rounded(2)->toString(), 'Positive half rounding must go away from zero.');
        assertSameValue('-1.24', Decimal::raw('-1.23500000')->rounded(2)->toString(), 'Negative half rounding must go away from zero.');
    },
    'division preserves raw scale' => static function (): void {
        assertSameValue('0.33333333', Decimal::raw('1.00000000')->divide(Decimal::raw('3.00000000'))->toString(), 'Division must preserve raw scale.');
    },
    'score values clamp to four decimal places' => static function (): void {
        assertSameValue('12.3457', Decimal::score('12.34567')->toString(), 'Score decimals must use four places.');
    },
    'comparison works across score and raw scales' => static function (): void {
        assertSameValue(0, Decimal::raw('2.50000000')->compareTo(Decimal::score('2.5000')), 'Cross-scale comparison must be exact.');
    },
];

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo '[OK] ' . $name . PHP_EOL;
}

echo PHP_EOL . 'ALL DECIMAL EDGE TESTS PASSED: ' . $passed . PHP_EOL;
