<?php

declare(strict_types=1);

namespace MacroRisk\Config;

use MacroRisk\DecimalMath;
use Exception;

/**
 * MacroRisk System Preset Manager (v1.5.0-comprehensive / Pure PHP Native)
 *
 * Defines official System Preset #1 configurations, indicator weights, frequency discounts,
 * and threshold boundaries for risk score calculation.
 */
final class SystemPreset
{
    public const DEFAULT_VERSION_ID = 1;

    /**
     * Returns the complete default indicator matrix for System Preset #1.
     * Guaranteed total sum of original_weight = 100.0000%.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getDefaultPreset(): array
    {
        return [
            // 1. Financial: Household Debt Service Ratio (DSR) - Required (20.0000%)
            'debt_service_ratio' => [
                'indicator_key'            => 'debt_service_ratio',
                'category'                 => 'financial',
                'original_weight'          => '20.0000',
                'is_required'              => true,
                'transformation_type'      => 'none',
                'normalization_method'     => 'threshold_linear',
                'direction_of_deterioration'=> 'higher_is_riskier',
                'low_risk_threshold'       => '13.00000000',
                'high_risk_threshold'      => '15.50000000',
                'frequency_discount'       => '0.7000',
                'production_allowed'       => true,
            ],

            // 2. Housing: Housing Starts to Population Growth Ratio (20.0000%)
            'housing_starts' => [
                'indicator_key'            => 'housing_starts',
                'category'                 => 'housing',
                'original_weight'          => '20.0000',
                'is_required'              => false,
                'transformation_type'      => 'none',
                'normalization_method'     => 'threshold_linear',
                'direction_of_deterioration'=> 'higher_is_riskier',
                'low_risk_threshold'       => '2.00000000',
                'high_risk_threshold'      => '5.00000000',
                'frequency_discount'       => '0.7000',
                'production_allowed'       => true,
            ],

            // 3. Financial: Yield Curve Spread / Benchmark Bond Yield (20.0000%)
            'bond_yield_10y' => [
                'indicator_key'            => 'bond_yield_10y',
                'category'                 => 'financial',
                'original_weight'          => '20.0000',
                'is_required'              => false,
                'transformation_type'      => 'none',
                'normalization_method'     => 'threshold_linear',
                'direction_of_deterioration'=> 'lower_is_riskier',
                'low_risk_threshold'       => '1.00000000',
                'high_risk_threshold'      => '-1.00000000',
                'frequency_discount'       => '1.0000',
                'production_allowed'       => true,
            ],

            // 4. Macro: Labor Productivity Index (20.0000%)
            'labor_productivity' => [
                'indicator_key'            => 'labor_productivity',
                'category'                 => 'macro',
                'original_weight'          => '20.0000',
                'is_required'              => false,
                'transformation_type'      => 'none',
                'normalization_method'     => 'threshold_linear',
                'direction_of_deterioration'=> 'lower_is_riskier',
                'low_risk_threshold'       => '108.00000000',
                'high_risk_threshold'      => '100.00000000',
                'frequency_discount'       => '0.7000',
                'production_allowed'       => true,
            ],

            // 5. Financial: Corporate & Business Insolvencies (20.0000%)
            'business_insolvencies' => [
                'indicator_key'            => 'business_insolvencies',
                'category'                 => 'financial',
                'original_weight'          => '20.0000',
                'is_required'              => false,
                'transformation_type'      => 'none',
                'normalization_method'     => 'threshold_linear',
                'direction_of_deterioration'=> 'higher_is_riskier',
                'low_risk_threshold'       => '5.00000000',
                'high_risk_threshold'      => '35.00000000',
                'frequency_discount'       => '1.0000',
                'production_allowed'       => true,
            ],
        ];
    }

    /**
     * Validates that the sum of original_weight across all indicators in the preset
     * strictly equals 100.0000% using BCMath.
     *
     * @param array<string, array<string, mixed>> $preset
     * @throws Exception
     */
    public static function validatePresetWeightSum(array $preset): void
    {
        $sum = '0.0000';
        foreach ($preset as $key => $config) {
            $weight = DecimalMath::clean((string)($config['original_weight'] ?? '0.0000'), DecimalMath::SCALE_SCORE);
            $sum = DecimalMath::add($sum, $weight, DecimalMath::SCALE_SCORE);
        }

        if (DecimalMath::comp($sum, '100.0000', DecimalMath::SCALE_SCORE) !== 0) {
            throw new Exception("INVALID_WEIGHT_SUM: System preset weight sum must be exactly 100.0000%. Given: {$sum}%");
        }
    }
}