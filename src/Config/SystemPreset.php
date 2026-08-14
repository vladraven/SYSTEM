<?php

declare(strict_types=1);

namespace MacroRisk\Config;

use MacroRisk\Core\Math\Decimal;
use RuntimeException;

final class SystemPreset
{
    public const DEFAULT_CONFIGURATION_VERSION = '2026.08.14';
    public const DEFAULT_MODEL_VERSION = '2026.08.14';

    public static function defaultSystemConfig(): array
    {
        return [
            'schema_version' => 1,
            'risk_bands' => [
                'very_low' => ['min' => '0.0000', 'max' => '20.0000'],
                'low' => ['min' => '20.0000', 'max' => '40.0000'],
                'moderate' => ['min' => '40.0000', 'max' => '60.0000'],
                'high' => ['min' => '60.0000', 'max' => '80.0000'],
                'severe' => ['min' => '80.0000', 'max' => '100.0000'],
            ],
            'calculation' => [
                'min_coverage_ratio' => '60.0000',
                'min_eligible_indicators' => 3,
            ],
            'classification_layers' => [
                'observation' => 'Observation',
                'transformation' => 'Transformation',
                'model_output' => 'Model Output',
                'interpretation' => 'Interpretation',
                'hypothesis' => 'Hypothesis',
            ],
            'scientific_integrity' => [
                'disclaimer' => 'MacroRisk is a deterministic monitoring model. It is not a forecast or prediction engine.',
            ],
        ];
    }

    public static function defaultIndicators(): array
    {
        $indicators = [
            'policy_rate' => [
                'indicator_key' => 'policy_rate',
                'title' => 'Bank of Canada Overnight Rate',
                'category' => 'monetary',
                'original_weight' => '20.0000',
                'is_required' => true,
                'normalization_method' => 'threshold_linear',
                'direction_of_deterioration' => 'higher_is_riskier',
                'low_risk_threshold' => '1.00000000',
                'high_risk_threshold' => '5.00000000',
                'frequency_discount' => '1.0000',
                'source_key' => 'bank_of_canada',
                'source_series_id' => 'V39079',
                'source_series_title' => 'Target for the overnight rate (business daily)',
                'unit' => 'percent',
                'frequency' => 'daily',
            ],
            'cpi_inflation' => [
                'indicator_key' => 'cpi_inflation',
                'title' => 'CPI All-items Inflation (Year-over-Year %)',
                'category' => 'prices',
                'original_weight' => '25.0000',
                'is_required' => true,
                'normalization_method' => 'threshold_linear',
                'direction_of_deterioration' => 'higher_is_riskier',
                'low_risk_threshold' => '2.00000000',
                'high_risk_threshold' => '5.00000000',
                'frequency_discount' => '0.9000',
                'source_key' => 'statcan_wds',
                'source_series_id' => '41690973',
                'source_series_title' => 'Canada;All-items',
                'unit' => 'percent_yoy',
                'source_unit' => 'cpi_index',
                'frequency' => 'monthly',
                'transformation_method' => 'year_over_year_percent_change',
                'transformation_note' => 'The verified StatCan vector provides the all-items CPI index level. MacroRisk derives the year-over-year percentage change from the latest observation and the same reference month 12 months earlier.',
            ],
            'unemployment_rate' => [
                'indicator_key' => 'unemployment_rate',
                'title' => 'Unemployment Rate (Canada, LFS)',
                'category' => 'labour',
                'original_weight' => '20.0000',
                'is_required' => false,
                'normalization_method' => 'threshold_linear',
                'direction_of_deterioration' => 'higher_is_riskier',
                'low_risk_threshold' => '5.00000000',
                'high_risk_threshold' => '9.00000000',
                'frequency_discount' => '0.9000',
                'source_key' => 'statcan_wds',
                'source_series_id' => '2062815',
                'source_series_title' => 'Canada;Unemployment rate;Total - Gender;15 years and over;Estimate;Seasonally adjusted',
                'unit' => 'percent',
                'frequency' => 'monthly',
            ],
            'bond_yield_10y' => [
                'indicator_key' => 'bond_yield_10y',
                'title' => 'Government of Canada 10-year Bond Yield',
                'category' => 'financial',
                'original_weight' => '20.0000',
                'is_required' => false,
                'normalization_method' => 'threshold_linear',
                'direction_of_deterioration' => 'lower_is_riskier',
                'low_risk_threshold' => '2.00000000',
                'high_risk_threshold' => '0.50000000',
                'frequency_discount' => '1.0000',
                'source_key' => 'bank_of_canada',
                'source_series_id' => 'V122487',
                'source_series_title' => 'Government of Canada benchmark bond yield, 10 year',
                'unit' => 'percent',
                'frequency' => 'daily',
            ],
            'housing_starts' => [
                'indicator_key' => 'housing_starts',
                'title' => 'Housing Starts (Canada, Seasonally Adjusted Annual Rate)',
                'category' => 'housing',
                'original_weight' => '15.0000',
                'is_required' => false,
                'normalization_method' => 'threshold_linear',
                'direction_of_deterioration' => 'lower_is_riskier',
                'low_risk_threshold' => '220000.00000000',
                'high_risk_threshold' => '150000.00000000',
                'frequency_discount' => '0.8000',
                'source_key' => 'statcan_wds',
                'source_series_id' => '729949',
                'source_series_title' => 'Canada;Housing starts;Total units',
                'unit' => 'units_saar',
                'source_unit' => 'units_monthly',
                'frequency' => 'monthly',
                'transformation_method' => 'annualize_monthly_total_units',
                'transformation_note' => 'The supplied vector 729945 was invalid in StatCan WDS on 2026-08-14. MacroRisk uses verified vector 729949 and annualizes the monthly observation by multiplying by 12.00000000 to align with the configured units_saar thresholds.',
            ],
        ];

        self::validateIndicatorWeightSum($indicators);

        return [
            'schema_version' => 1,
            'indicators' => $indicators,
        ];
    }

    public static function defaultSources(): array
    {
        return [
            'schema_version' => 1,
            'sources' => [
                'statcan_wds' => [
                    'source_key' => 'statcan_wds',
                    'title' => 'Statistics Canada Web Data Service',
                    'base_url' => 'https://www150.statcan.gc.ca/t1/wds/rest/',
                    'classification' => 'Observation',
                    'official' => true,
                    'license' => 'Statistics Canada Open Licence',
                ],
                'bank_of_canada' => [
                    'source_key' => 'bank_of_canada',
                    'title' => 'Bank of Canada Valet API',
                    'base_url' => 'https://www.bankofcanada.ca/valet/',
                    'classification' => 'Observation',
                    'official' => true,
                    'license' => 'Bank of Canada terms of use',
                ],
                'open_government' => [
                    'source_key' => 'open_government',
                    'title' => 'Government of Canada Open Government Portal',
                    'base_url' => 'https://open.canada.ca/data/en/api/3/action/',
                    'classification' => 'Observation',
                    'official' => true,
                    'license' => 'Open Government Licence - Canada',
                ],
            ],
        ];
    }

    public static function defaultModelVersions(): array
    {
        return [
            'schema_version' => 1,
            'active_model_version' => self::DEFAULT_MODEL_VERSION,
            'active_configuration_version' => self::DEFAULT_CONFIGURATION_VERSION,
            'models' => [
                self::DEFAULT_MODEL_VERSION => [
                    'model_version' => self::DEFAULT_MODEL_VERSION,
                    'configuration_version' => self::DEFAULT_CONFIGURATION_VERSION,
                    'classification' => 'Model Output',
                    'description' => 'JSON-native deterministic MacroRisk production model using five official Canadian indicators.',
                ],
            ],
        ];
    }

    public static function defaultScenarioRules(): array
    {
        $rules = [
            [
                'id' => 'cpi_surprise_high',
                'trigger_indicator' => 'cpi_inflation',
                'trigger_direction' => 'above',
                'trigger_threshold' => '3.50000000',
                'classification' => 'hypothesis',
                'disclaimer' => 'This is an analytical hypothesis derived from historical Bank of Canada policy patterns, not a prediction.',
                'methodology_note' => 'Based on descriptive historical frequency of Bank of Canada rate responses to CPI readings above 3.5% over 2010-2024. Informational only; this is not a statistical forecast model.',
                'branches' => [
                    [
                        'id' => 'rate_increase_fast',
                        'name' => 'Accelerated Rate Response',
                        'description' => 'Historical pattern: CPI above 3.5% has often been followed by consecutive policy-tightening meetings. This branch represents the more frequent historical response pattern.',
                        'probability_weight' => '60.0000',
                        'time_window' => '1-3 policy meetings (~6-18 weeks)',
                        'affected_indicators' => [
                            'policy_rate' => ['direction' => 'up', 'range' => '+0.25 to +0.75 percentage points'],
                            'bond_yield_10y' => ['direction' => 'up', 'range' => '+0.10 to +0.40 percentage points'],
                            'housing_starts' => ['direction' => 'down', 'range' => 'possible softening; magnitude uncertain'],
                        ],
                    ],
                    [
                        'id' => 'rate_increase_gradual',
                        'name' => 'Gradual Rate Response',
                        'description' => 'Historical pattern: the Bank of Canada may treat inflation pressure as partly transitory and respond with a single measured increase while monitoring later releases.',
                        'probability_weight' => '30.0000',
                        'time_window' => '1-2 policy meetings (~6-12 weeks)',
                        'affected_indicators' => [
                            'policy_rate' => ['direction' => 'up', 'range' => '+0.25 percentage points'],
                            'bond_yield_10y' => ['direction' => 'neutral_to_up', 'range' => '-0.10 to +0.20 percentage points'],
                        ],
                    ],
                    [
                        'id' => 'rate_hold',
                        'name' => 'Hold and Monitor',
                        'description' => 'Historical pattern: the Bank of Canada may hold the policy rate steady while citing transitory factors, uncertainty, or offsetting economic weakness.',
                        'probability_weight' => '10.0000',
                        'time_window' => '1-2 policy meetings (~6-12 weeks)',
                        'affected_indicators' => [
                            'policy_rate' => ['direction' => 'neutral', 'range' => 'no change expected'],
                        ],
                    ],
                ],
            ],
        ];

        self::validateScenarioProbabilitySums($rules);

        return [
            'schema_version' => 1,
            'classification' => 'hypothesis',
            'disclaimer' => 'These scenario branches are analytical hypotheses only, not forecasts or predictions. They are based on historical patterns and are informational only.',
            'rules' => $rules,
        ];
    }

    public static function validateIndicatorWeightSum(array $indicators): void
    {
        $sum = Decimal::score('0.0000');

        foreach ($indicators as $indicator) {
            $sum = $sum->add(
                Decimal::score((string) ($indicator['original_weight'] ?? '0.0000'))
            );
        }

        if ($sum->rounded(Decimal::SCALE_SCORE)->compareTo(Decimal::score('100.0000')) !== 0) {
            throw new RuntimeException(
                'Configured indicator weights must sum exactly to 100.0000.'
            );
        }
    }

    public static function validateScenarioProbabilitySums(array $rules): void
    {
        foreach ($rules as $rule) {
            $sum = Decimal::score('0.0000');

            foreach (($rule['branches'] ?? []) as $branch) {
                $sum = $sum->add(
                    Decimal::score((string) ($branch['probability_weight'] ?? '0.0000'))
                );
            }

            if ($sum->rounded(Decimal::SCALE_SCORE)->compareTo(Decimal::score('100.0000')) !== 0) {
                throw new RuntimeException(
                    sprintf('Scenario rule %s must sum to 100.0000.', (string) ($rule['id'] ?? 'unknown'))
                );
            }
        }
    }
}
