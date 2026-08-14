<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MacroRisk\Application\Scenario\ScenarioEngine;
use MacroRisk\Config\SystemPreset;
use MacroRisk\Core\Security\ScientificIntegrityGuard;
use MacroRisk\Engine\RiskEngine;
use MacroRisk\Infrastructure\Storage\ConfigurationRepository;
use MacroRisk\Infrastructure\Storage\StorageBootstrapper;

$storageRoot = __DIR__ . '/fixtures/scenario_runtime/storage';
if (is_dir($storageRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($storageRoot);
}
(new StorageBootstrapper($storageRoot))->bootstrap();
$repo = new ConfigurationRepository($storageRoot);
$engine = new ScenarioEngine(new RiskEngine(), $repo, new ScientificIntegrityGuard());
$config = SystemPreset::defaultIndicators()['indicators'];
$system = SystemPreset::defaultSystemConfig();
$snapshot = [
    'policy_rate' => ['status' => 'ok', 'value' => '2.25000000', 'observation_value' => '2.25000000'],
    'cpi_inflation' => ['status' => 'ok', 'value' => '4.20000000', 'observation_value' => '4.20000000'],
    'unemployment_rate' => ['status' => 'ok', 'value' => '6.20000000', 'observation_value' => '6.20000000'],
    'bond_yield_10y' => ['status' => 'ok', 'value' => '3.10000000', 'observation_value' => '3.10000000'],
    'housing_starts' => ['status' => 'ok', 'value' => '180000.00000000', 'observation_value' => '15000.00000000'],
];

$tests = [
    'scenario probabilities sum to 100.0000 and include disclaimer' => static function () use ($engine, $config, $system, $snapshot): void {
        $result = $engine->simulate($config, $snapshot, $system, ['cpi_inflation' => '4.20000000']);
        assertSameValue('hypothesis', $result['classification'], 'Scenario classification must be hypothesis.');
        assertTrueValue($result['disclaimer'] !== '', 'Scenario disclaimer must be present.');
        $sum = '0.0000';
        foreach ($result['triggered_rules'][0]['branches'] as $branch) {
            $sum = bcadd($sum, $branch['probability_weight'], 4);
        }
        assertSameValue('100.0000', $sum, 'Scenario branch weights must sum to 100.0000.');
    },
    'scientific integrity guard blocks banned phrases' => static function (): void {
        $guard = new ScientificIntegrityGuard();
        assertThrows(MacroRisk\Core\Exceptions\ScientificIntegrityViolationException::class, static function () use ($guard): void {
            $guard->screen('The model proves that a recession is certain.');
        }, 'Banned phrases must be rejected.');
    },
];

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    $passed++;
    echo '[OK] ' . $name . PHP_EOL;
}

echo PHP_EOL . 'ALL SCENARIO TESTS PASSED: ' . $passed . PHP_EOL;
