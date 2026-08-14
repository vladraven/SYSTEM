<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MacroRisk\Config\SystemPreset;
use MacroRisk\Engine\RiskEngine;

$engine = new RiskEngine();
$config = SystemPreset::defaultIndicators()['indicators'];
$system = SystemPreset::defaultSystemConfig();
$context = [
    'vintage_date' => '2026-08-14T00:00:00+00:00',
    'configuration_version' => 'test-config',
    'model_version' => 'test-model',
    'configuration_hash' => str_repeat('a', 40),
    'system_config_hash' => str_repeat('b', 40),
];

$tests = [
    'ok calculation reconciles weights to exactly 100.0000' => static function () use ($engine, $config, $system, $context): void {
        $snapshot = [
            'policy_rate' => ['status' => 'ok', 'value' => '5.00000000', 'observation_value' => '5.00000000'],
            'cpi_inflation' => ['status' => 'ok', 'value' => '5.00000000', 'observation_value' => '5.00000000'],
            'unemployment_rate' => ['status' => 'ok', 'value' => '9.00000000', 'observation_value' => '9.00000000'],
            'bond_yield_10y' => ['status' => 'ok', 'value' => '0.50000000', 'observation_value' => '0.50000000'],
            'housing_starts' => ['status' => 'ok', 'value' => '150000.00000000', 'observation_value' => '12500.00000000'],
        ];
        $result = $engine->calculate($config, $snapshot, $system, $context);
        assertSameValue('ok', $result['calculation_status'], 'Calculation should be ok.');
        assertSameValue('100.0000', $result['effective_weights_sum'], 'Effective weights must sum exactly to 100.0000.');
        assertSameValue('severe', $result['risk_band'], 'Risk band should resolve from config.');
    },
    'low coverage nulls risk score and band' => static function () use ($engine, $config, $system, $context): void {
        $snapshot = [
            'policy_rate' => ['status' => 'ok', 'value' => '2.25000000', 'observation_value' => '2.25000000'],
            'cpi_inflation' => ['status' => 'source_failure', 'missing_reason' => 'no_historical_data'],
            'unemployment_rate' => ['status' => 'source_failure', 'missing_reason' => 'no_historical_data'],
            'bond_yield_10y' => ['status' => 'source_failure', 'missing_reason' => 'no_historical_data'],
            'housing_starts' => ['status' => 'ok', 'value' => '180000.00000000', 'observation_value' => '15000.00000000'],
        ];
        $result = $engine->calculate($config, $snapshot, $system, $context);
        assertSameValue('insufficient_data', $result['calculation_status'], 'Fewer than three eligible indicators should be insufficient_data.');
        assertSameValue(null, $result['risk_score'], 'Non-ok result must have null risk score.');
        assertSameValue(null, $result['risk_band'], 'Non-ok result must have null risk band.');
    },
    'calculation hash is deterministic' => static function () use ($engine, $config, $system, $context): void {
        $snapshot = [
            'policy_rate' => ['status' => 'ok', 'value' => '2.25000000', 'observation_value' => '2.25000000'],
            'cpi_inflation' => ['status' => 'ok', 'value' => '4.20000000', 'observation_value' => '4.20000000'],
            'unemployment_rate' => ['status' => 'ok', 'value' => '6.40000000', 'observation_value' => '6.40000000'],
            'bond_yield_10y' => ['status' => 'ok', 'value' => '3.20000000', 'observation_value' => '3.20000000'],
            'housing_starts' => ['status' => 'ok', 'value' => '180000.00000000', 'observation_value' => '15000.00000000'],
        ];
        $first = $engine->calculate($config, $snapshot, $system, $context);
        $second = $engine->calculate($config, $snapshot, $system, $context);
        assertSameValue($first['calculation_hash'], $second['calculation_hash'], 'Calculation hash must be deterministic.');
    },
];

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo '[OK] ' . $name . PHP_EOL;
}

echo PHP_EOL . 'ALL RISK ENGINE TESTS PASSED: ' . $passed . PHP_EOL;
