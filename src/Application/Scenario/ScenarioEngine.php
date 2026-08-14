<?php

declare(strict_types=1);

namespace MacroRisk\Application\Scenario;

use MacroRisk\Config\SystemPreset;
use MacroRisk\Core\Math\Decimal;
use MacroRisk\Core\Security\ScientificIntegrityGuard;
use MacroRisk\Domain\Scenario\ScenarioBranch;
use MacroRisk\Domain\Scenario\ScenarioRule;
use MacroRisk\Engine\RiskEngine;
use MacroRisk\Infrastructure\Storage\ConfigurationRepository;

final class ScenarioEngine
{
    public function __construct(
        private readonly RiskEngine $riskEngine,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly ScientificIntegrityGuard $integrityGuard
    ) {
    }

    public function simulate(
        array $indicatorConfigs,
        array $snapshot,
        array $systemConfig,
        array $overrides = [],
        array $context = []
    ): array {
        $simulationSnapshot = $snapshot;

        foreach ($overrides as $indicatorKey => $overrideValue) {
            if (!isset($indicatorConfigs[$indicatorKey]) || !is_string($overrideValue)) {
                continue;
            }

            $value = Decimal::raw($overrideValue)->toString();
            $simulationSnapshot[$indicatorKey] = array_merge(
                $simulationSnapshot[$indicatorKey] ?? [],
                [
                    'status' => 'user_override',
                    'missing_reason' => null,
                    'value' => $value,
                    'observation_value' => $value,
                    'reference_period' => 'simulation_override',
                    'release_time' => null,
                    'retrieved_at' => gmdate('c'),
                ]
            );
        }

        $calculation = $this->riskEngine->calculate(
            $indicatorConfigs,
            $simulationSnapshot,
            $systemConfig,
            $context,
            'simulation'
        );

        $scenarioConfig = $this->configurationRepository->getScenarioRulesConfig();
        $this->integrityGuard->screen((string) ($scenarioConfig['disclaimer'] ?? ''));
        $rules = $scenarioConfig['rules'] ?? [];
        SystemPreset::validateScenarioProbabilitySums($rules);
        $triggeredRules = [];

        foreach ($rules as $rule) {
            $indicatorKey = (string) ($rule['trigger_indicator'] ?? '');
            $direction = (string) ($rule['trigger_direction'] ?? '');
            $threshold = Decimal::raw((string) ($rule['trigger_threshold'] ?? '0.00000000'));
            $currentValue = $simulationSnapshot[$indicatorKey]['value'] ?? null;

            if (!is_string($currentValue)) {
                continue;
            }

            $currentDecimal = Decimal::raw($currentValue);
            $triggered = $direction === 'above'
                ? $currentDecimal->compareTo($threshold) > 0
                : $currentDecimal->compareTo($threshold) < 0;

            if (!$triggered) {
                continue;
            }

            $branches = [];

            foreach (($rule['branches'] ?? []) as $branch) {
                $scenarioBranch = new ScenarioBranch(
                    (string) ($branch['id'] ?? ''),
                    (string) ($branch['name'] ?? ''),
                    (string) ($branch['description'] ?? ''),
                    (string) ($branch['probability_weight'] ?? '0.0000'),
                    (string) ($branch['time_window'] ?? ''),
                    is_array($branch['affected_indicators'] ?? null)
                        ? $branch['affected_indicators']
                        : []
                );
                $branches[] = $scenarioBranch;
            }

            $scenarioRule = new ScenarioRule(
                (string) ($rule['id'] ?? ''),
                $indicatorKey,
                $direction,
                $threshold->toString(),
                (string) ($rule['classification'] ?? 'hypothesis'),
                (string) ($rule['disclaimer'] ?? ''),
                (string) ($rule['methodology_note'] ?? ''),
                $branches
            );

            $ruleArray = $scenarioRule->toArray();
            foreach ($ruleArray['branches'] as $index => $branch) {
                $ruleArray['branches'][$index]['classification'] = $ruleArray['classification'];
                $ruleArray['branches'][$index]['disclaimer'] = $ruleArray['disclaimer'];
            }
            $this->screenScenarioText($ruleArray);
            $triggeredRules[] = $ruleArray;
        }

        return [
            'classification' => (string) ($scenarioConfig['classification'] ?? 'hypothesis'),
            'disclaimer' => (string) ($scenarioConfig['disclaimer'] ?? ''),
            'simulated_score' => $calculation['risk_score'],
            'simulated_band' => $calculation['risk_band'],
            'simulation' => $calculation,
            'triggered_rules' => $triggeredRules,
        ];
    }

    private function screenScenarioText(array $rule): void
    {
        $text = implode("\n", [
            (string) ($rule['disclaimer'] ?? ''),
            (string) ($rule['methodology_note'] ?? ''),
        ]);

        foreach (($rule['branches'] ?? []) as $branch) {
            $text .= "\n" . (string) ($branch['name'] ?? '');
            $text .= "\n" . (string) ($branch['description'] ?? '');
            $text .= "\n" . (string) ($branch['time_window'] ?? '');
        }

        $this->integrityGuard->screen($text);
    }
}
