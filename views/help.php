<?php
/** @var \MacroRisk\Controller\DashboardController $controller */
$helpData = $controller->getHelpData();
$indicators = $helpData['indicators'];
$system = $helpData['system'];
$sources = $helpData['sources']['sources'] ?? [];
$scenarioRules = $helpData['scenario_rules'];
$e = static fn (?string $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formulas = [
    'threshold_linear/higher_is_riskier' => 'x <= low => 0; x >= high => 100; otherwise ((x-low)/(high-low))*100',
    'threshold_linear/lower_is_riskier' => 'x >= low => 0; x <= high => 100; otherwise ((low-x)/(low-high))*100',
    'distance_from_target_is_riskier' => 'min(100, abs(x-target)/max_deviation*100)',
    'outside_band_is_riskier' => 'inside safe band = 0; at or beyond outside boundary = 100; between boundaries = linear interpolation',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MacroRisk Help</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; color: #1f2937; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
        th, td { border: 1px solid #d1d5db; padding: 0.6rem; text-align: left; vertical-align: top; }
        .card { border: 1px solid #d1d5db; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <h1>MacroRisk help and methodology</h1>
    <p><a href="./">Back to dashboard</a></p>

    <div class="card">
        <h2>Important limitation</h2>
        <p><?= $e($system['scientific_integrity']['disclaimer'] ?? 'MacroRisk is not a forecast or prediction engine.') ?></p>
        <p>Scenario output is classified as <strong>Hypothesis</strong>. Model scores are <strong>Model Output</strong>, not observed facts.</p>
    </div>

    <h2>Indicators</h2>
    <table>
        <thead>
        <tr>
            <th>Indicator</th>
            <th>Source API</th>
            <th>Normalization</th>
            <th>Thresholds</th>
            <th>Weight</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($indicators as $indicator): ?>
            <tr>
                <td>
                    <strong><?= $e($indicator['title']) ?></strong><br>
                    <?= $e($indicator['source_series_title'] ?? '') ?>
                </td>
                <td>
                    <?= $e($sources[$indicator['source_key']]['title'] ?? $indicator['source_key']) ?><br>
                    <span><?= $e($indicator['source_series_id']) ?></span>
                </td>
                <td><?= $e($indicator['normalization_method']) ?> / <?= $e($indicator['direction_of_deterioration'] ?? '') ?></td>
                <td>
                    low = <?= $e($indicator['low_risk_threshold'] ?? '') ?><br>
                    high = <?= $e($indicator['high_risk_threshold'] ?? '') ?>
                    <?php if (!empty($indicator['transformation_note'])): ?>
                        <br><span><?= $e($indicator['transformation_note']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $e($indicator['original_weight']) ?> (discount <?= $e($indicator['frequency_discount']) ?>)</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Normalization formulas</h2>
    <table>
        <thead><tr><th>Method</th><th>Formula</th></tr></thead>
        <tbody>
        <?php foreach ($formulas as $name => $formula): ?>
            <tr><td><?= $e($name) ?></td><td><?= $e($formula) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="card">
        <h2>Weights and coverage</h2>
        <p>Configured original weights must sum to exactly 100.0000.</p>
        <p>Production requires coverage ratio &gt;= <?= $e($system['calculation']['min_coverage_ratio'] ?? '') ?>, at least <?= $e((string) ($system['calculation']['min_eligible_indicators'] ?? 0)) ?> eligible indicators, and all required indicators eligible.</p>
        <p>Coverage uses eligible original weights only. After coverage passes, frequency discounts are applied and eligible weights are renormalized to 100.0000 using round-half-away-from-zero reconciliation.</p>
    </div>

    <div class="card">
        <h2>Scenario engine</h2>
        <p>Scenario rules are versioned in <code>storage/config/scenario_rules.json</code>.</p>
        <p>Each scenario response includes classification <strong><?= $e($scenarioRules['classification'] ?? 'hypothesis') ?></strong> and a disclaimer.</p>
        <p><?= $e($scenarioRules['disclaimer'] ?? '') ?></p>
        <p>Methodology notes describe historical pattern analysis and informational limits. The scenario engine recomputes the deterministic score using user overrides and then checks which hypothesis rules are triggered.</p>
    </div>
</body>
</html>
