<?php
/** @var \MacroRisk\Controller\DashboardController $controller */
$dashboardData = $controller->getDashboardData();
$indicators = $dashboardData['indicators'];
$snapshot = $dashboardData['snapshot']['indicators'];
$calculation = $dashboardData['calculation'];
$system = $dashboardData['system'];
$scenarioRules = $dashboardData['scenario_rules'];

$e = static fn (?string $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusLabel = $calculation['calculation_status'] === 'ok'
    ? 'Production calculation available'
    : 'Calculation unavailable: ' . (string) $calculation['calculation_status'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MacroRisk Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; color: #1f2937; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
        th, td { border: 1px solid #d1d5db; padding: 0.6rem; text-align: left; vertical-align: top; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .muted { color: #6b7280; font-size: 0.92rem; }
        input[type=range] { width: 100%; }
        pre { white-space: pre-wrap; background: #f9fafb; border: 1px solid #e5e7eb; padding: 1rem; }
        .pill { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 999px; background: #eef2ff; }
    </style>
</head>
<body>
    <h1>MacroRisk Dashboard</h1>
    <p><a href="?action=help">Help and methodology</a></p>

    <div class="grid">
        <div class="card">
            <h2>Current model output</h2>
            <p><strong>Status:</strong> <?= $e($statusLabel) ?></p>
            <p><strong>Risk score:</strong> <?= $e($calculation['risk_score'] ?? 'data unavailable') ?></p>
            <p><strong>Risk band:</strong> <?= $e($calculation['risk_band'] ?? 'data unavailable') ?></p>
            <p><strong>Coverage ratio:</strong> <?= $e($calculation['coverage_ratio'] ?? '0.0000') ?>%</p>
            <p><strong>Calculation hash:</strong> <span class="pill"><?= $e($calculation['calculation_hash'] ?? '') ?></span></p>
        </div>
        <div class="card">
            <h2>Model status</h2>
            <p><strong>Vintage:</strong> <?= $e($dashboardData['snapshot']['vintage_date'] ?? '') ?></p>
            <p><strong>Eligible indicators:</strong> <?= $e((string) ($calculation['eligible_indicator_count'] ?? 0)) ?></p>
            <p><strong>Minimum coverage:</strong> <?= $e($system['calculation']['min_coverage_ratio'] ?? '') ?>%</p>
            <p><strong>Minimum eligible indicators:</strong> <?= $e((string) ($system['calculation']['min_eligible_indicators'] ?? 0)) ?></p>
        </div>
    </div>

    <h2>Indicators</h2>
    <table>
        <thead>
        <tr>
            <th>Indicator</th>
            <th>Current value</th>
            <th>Reference period</th>
            <th>Source</th>
            <th>Weight</th>
            <th>Effective weight</th>
            <th>Normalized score</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($indicators as $indicatorKey => $config): ?>
            <?php $item = $snapshot[$indicatorKey] ?? []; $detail = $calculation['indicators'][$indicatorKey] ?? []; ?>
            <tr>
                <td>
                    <strong><?= $e($config['title']) ?></strong><br>
                    <span class="muted"><?= $e($config['source_series_title'] ?? '') ?></span>
                </td>
                <td>
                    <?= $e($item['value'] ?? 'unavailable') ?>
                    <div class="muted">Observed: <?= $e($item['observation_value'] ?? 'n/a') ?></div>
                </td>
                <td><?= $e($item['reference_period'] ?? 'n/a') ?><br><span class="muted">Released: <?= $e($item['release_time'] ?? 'n/a') ?></span></td>
                <td>
                    <?php if (!empty($item['source_link'])): ?>
                        <a href="<?= $e($item['source_link']) ?>" target="_blank" rel="noreferrer">official source</a>
                    <?php else: ?>
                        <?= $e($item['source_key'] ?? 'n/a') ?>
                    <?php endif; ?>
                    <div class="muted">Series: <?= $e($config['source_series_id']) ?></div>
                </td>
                <td><?= $e($config['original_weight']) ?></td>
                <td><?= $e($detail['effective_weight'] ?? '0.0000') ?></td>
                <td><?= $e($detail['normalized_score'] ?? 'n/a') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Scenario / hypothesis explorer</h2>
    <p class="muted">Scenario output is analytical hypothesis content only. It is not a forecast or prediction.</p>
    <div class="grid">
        <?php foreach ($indicators as $indicatorKey => $config): ?>
            <?php
            $item = $snapshot[$indicatorKey] ?? [];
            $current = (float) ($item['value'] ?? $config['low_risk_threshold'] ?? 0);
            $low = isset($config['low_risk_threshold']) ? (float) $config['low_risk_threshold'] : $current;
            $high = isset($config['high_risk_threshold']) ? (float) $config['high_risk_threshold'] : $current;
            $min = min($low, $high, $current);
            $max = max($low, $high, $current);
            $step = $max > 1000 ? '100' : '0.1';
            ?>
            <div class="card">
                <label for="slider_<?= $e($indicatorKey) ?>"><strong><?= $e($config['title']) ?></strong></label>
                <input
                    id="slider_<?= $e($indicatorKey) ?>"
                    type="range"
                    min="<?= $e((string) $min) ?>"
                    max="<?= $e((string) $max) ?>"
                    step="<?= $e($step) ?>"
                    value="<?= $e((string) $current) ?>"
                    data-indicator="<?= $e($indicatorKey) ?>"
                >
                <div class="muted">Current override: <span id="value_<?= $e($indicatorKey) ?>"><?= $e(number_format($current, 4, '.', '')) ?></span></div>
            </div>
        <?php endforeach; ?>
    </div>

    <p><button id="runScenario" type="button">Run scenario</button></p>
    <pre id="scenarioOutput"><?= $e(json_encode([
        'classification' => $scenarioRules['classification'] ?? 'hypothesis',
        'disclaimer' => $scenarioRules['disclaimer'] ?? '',
        'triggered_rules' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>

    <script>
    document.querySelectorAll('input[type="range"]').forEach(function (slider) {
        slider.addEventListener('input', function () {
            var target = document.getElementById('value_' + slider.dataset.indicator);
            if (target) {
                target.textContent = Number(slider.value).toFixed(4);
            }
        });
    });

    document.getElementById('runScenario').addEventListener('click', function () {
        var overrides = {};
        document.querySelectorAll('input[type="range"]').forEach(function (slider) {
            overrides[slider.dataset.indicator] = Number(slider.value).toFixed(8);
        });

        fetch('?action=scenario', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({overrides: overrides})
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            document.getElementById('scenarioOutput').textContent = JSON.stringify(data, null, 2);
        })
        .catch(function (error) {
            document.getElementById('scenarioOutput').textContent = String(error);
        });
    });
    </script>
</body>
</html>
