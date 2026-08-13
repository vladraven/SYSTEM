<?php

declare(strict_types=1);

namespace MacroRisk\Engine;

use MacroRisk\DecimalMath;
use Exception;

/**
 * MacroRisk Risk Engine (v1.5.0-comprehensive / Pure PHP Native)
 *
 * Deterministic mathematical risk calculation engine adhering strictly to BCMath decimal
 * arithmetic. Completely excludes native PHP floats to prevent IEEE 754 precision loss.
 */
final class RiskEngine
{
    public const MIN_COVERAGE_RATIO = '60.0000';
    public const MIN_INDICATORS_COUNT = 3;

    /**
     * Computes the macroeconomic risk score from a set of configured indicators and current snapshot values.
     *
     * @param array<string, array<string, mixed>> $indicatorConfigs Array of indicator configurations indexed by indicator_key.
     * @param array<string, string> $observations Array of raw observation values indexed by indicator_key.
     * @return array<string, mixed> Structured result matching risk_score_results and risk_score_indicator_contributions schema.
     */
    public function calculateScore(array $indicatorConfigs, array$observations): array
    {
        if (empty($indicatorConfigs)) {
            throw new Exception("INSUFFICIENT_DATA: No indicator configurations provided.");
        }

        $configuredCount = count($indicatorConfigs);$configuredWeightsSum = '0.0000';
        $availableWeightsSum = '0.0000';$availableCount = 0;
        $requiredMissing = false;

        $processedIndicators = [];

        // 1. Initial Pass: Validate configurations and compute raw/available weights
        foreach ($indicatorConfigs as $key =>$config) {
            $origWeight = DecimalMath::clean((string)($config['original_weight'] ?? '0.0000'), DecimalMath::SCALE_SCORE);
            $isRequired = (bool)($config['is_required'] ?? false);
            $configuredWeightsSum = DecimalMath::add($configuredWeightsSum,$origWeight, DecimalMath::SCALE_SCORE);

            $rawVal = isset($observations[$key]) ? DecimalMath::clean((string)$observations[$key], DecimalMath::SCALE_RAW) : null;
            $isAvailable = ($rawVal !== null);

            if ($isAvailable) {
                $availableCount++;$availableWeightsSum = DecimalMath::add($availableWeightsSum,$origWeight, DecimalMath::SCALE_SCORE);
            } elseif ($isRequired) {$requiredMissing = true;
            }

            $processedIndicators[$key] = [
                'config'         => $config,
                'key'            => $key,
                'raw_value'      => $rawVal,
                'is_available'   => $isAvailable,
                'is_required'    => $isRequired,
                'original_weight'=> $origWeight,
                'freq_discount'  => DecimalMath::clean((string)($config['frequency_discount'] ?? '1.0000'), DecimalMath::SCALE_SCORE),
            ];
        }

        // Verify total configured weights sum equals 100.0000%
        if (DecimalMath::comp($configuredWeightsSum, '100.0000', DecimalMath::SCALE_SCORE) !== 0) {
            throw new Exception("INVALID_WEIGHT_SUM: Total configured weights must equal 100.0000%. Given: {$configuredWeightsSum}");
        }

        // Compute coverage ratio: (SUM(available_orig_weights) / SUM(configured_orig_weights)) * 100
        $coverageRatio = DecimalMath::mul(
            DecimalMath::div($availableWeightsSum,$configuredWeightsSum, DecimalMath::SCALE_RAW),
            '100.0000',
            DecimalMath::SCALE_SCORE
        );

        // 2. Validate Coverage Invariants
        if ($availableCount < self::MIN_INDICATORS_COUNT) {
            throw new Exception("INSUFFICIENT_DATA: Minimum " . self::MIN_INDICATORS_COUNT . " available indicators required. Available: {$availableCount}");
        }

        if (DecimalMath::comp($coverageRatio, self::MIN_COVERAGE_RATIO, DecimalMath::SCALE_SCORE) < 0) {
            throw new Exception("LOW_COVERAGE: Coverage ratio {$coverageRatio}% is below minimum required " . self::MIN_COVERAGE_RATIO . "%");
        }

        if ($requiredMissing) {
            throw new Exception("REQUIRED_INDICATOR_MISSING: One or more required indicators are missing from the current snapshot.");
        }

        // 3. Weight Discounting & Renormalization
        // Step A: Base Renormalization -> w_i_base = (w_i_orig / SUM(w_available_orig)) * 100
        // Step B: Frequency Discounting -> w_i_disc = w_i_base * fd_i
        $discountedWeightsSum = '0.0000';

        foreach ($processedIndicators as $key => &$item) {
            if (!$item['is_available']) {
                $item['effective_weight'] = '0.0000';$item['normalized_score'] = null;
                $item['contribution_value'] = null;
                continue;
            }

            $baseWeight = DecimalMath::mul(
                DecimalMath::div($item['original_weight'],$availableWeightsSum, DecimalMath::SCALE_RAW),
                '100.0000',
                DecimalMath::SCALE_RAW
            );

            $discWeight = DecimalMath::mul($baseWeight,$item['freq_discount'], DecimalMath::SCALE_RAW);
            $item['disc_weight'] =$discWeight;

            $discountedWeightsSum = DecimalMath::add($discountedWeightsSum,$discWeight, DecimalMath::SCALE_RAW);
        }
        unset($item);

        // Step C: Final Effective Weights -> w_i_eff = (w_i_disc / SUM(w_disc)) * 100
        // Step D: Calculate Normalized Indicator Scores & Contribution Values
        $totalRiskScore = '0.0000';
        $effectiveWeightsSum = '0.0000';$contributions = [];

        foreach ($processedIndicators as $key =>$item) {
            if (!$item['is_available']) {
                $contributions[$key] = [
                    'indicator_key'            => $key,
                    'raw_value'                => null,
                    'transformed_value'        => null,
                    'normalized_indicator_score'=> null,
                    'original_weight'          => $item['original_weight'],
                    'frequency_discount'       => $item['freq_discount'],
                    'effective_weight'         => '0.0000',
                    'contribution_value'       => '0.0000',
                    'is_available'             => false,
                    'is_required'              => $item['is_required'],
                    'missing_reason'           => 'DATA_UNAVAILABLE_AT_VINTAGE',
                ];
                continue;
            }

            $effWeight = DecimalMath::mul(
                DecimalMath::div($item['disc_weight'],$discountedWeightsSum, DecimalMath::SCALE_RAW),
                '100.0000',
                DecimalMath::SCALE_SCORE
            );
            $effectiveWeightsSum = DecimalMath::add($effectiveWeightsSum,$effWeight, DecimalMath::SCALE_SCORE);

            $normScore =$this->normalizeValue($item['raw_value'],$item['config']);
            
            // Contribution: c_i = (score_i * effWeight_i) / 100
            $contribution = DecimalMath::div(
                DecimalMath::mul($normScore,$effWeight, DecimalMath::SCALE_RAW),
                '100.0000',
                DecimalMath::SCALE_SCORE
            );

            $totalRiskScore = DecimalMath::add($totalRiskScore,$contribution, DecimalMath::SCALE_SCORE);

            $contributions[$key] = [
                'indicator_key'            => $key,
                'raw_value'                => $item['raw_value'],
                'transformed_value'        => $item['raw_value'], // Direct linear transform
                'normalized_indicator_score'=> $normScore,
                'original_weight'          => $item['original_weight'],
                'frequency_discount'       => $item['freq_discount'],
                'effective_weight'         => $effWeight,
                'contribution_value'       => $contribution,
                'is_available'             => true,
                'is_required'              => $item['is_required'],
                'missing_reason'           => null,
            ];
        }

        // Clamp total risk score between 0.0000 and 100.0000
        $finalScore = DecimalMath::max('0.0000', DecimalMath::min('100.0000', $totalRiskScore, DecimalMath::SCALE_SCORE), DecimalMath::SCALE_SCORE);$riskBand = $this->determineRiskBand($finalScore);

        return [
            'risk_score'                 => $finalScore,
            'risk_band'                  => $riskBand,
            'coverage_ratio'             => $coverageRatio,
            'available_indicator_count'  => $availableCount,
            'configured_indicator_count' => $configuredCount,
            'required_indicator_missing' => false,
            'effective_weights_sum'      => $effectiveWeightsSum,
            'contributions'              => $contributions,
        ];
    }

    /**
     * Normalizes a raw observation value to a 0.0000–100.0000 risk score based on configuration thresholds.
     */
    public function normalizeValue(string $rawVal, array$config): string
    {
        $method    = (string)($config['normalization_method'] ?? 'threshold_linear');
        $direction = (string)($config['direction_of_deterioration'] ?? 'higher_is_riskier');

        $val  = DecimalMath::clean($rawVal, DecimalMath::SCALE_RAW);
        $low  = isset($config['low_risk_threshold']) ? DecimalMath::clean((string)$config['low_risk_threshold'], DecimalMath::SCALE_RAW) : '0.00000000';$high = isset($config['high_risk_threshold']) ? DecimalMath::clean((string)$config['high_risk_threshold'], DecimalMath::SCALE_RAW) : '100.00000000';

        if ($method === 'threshold_linear') {
            if ($direction === 'higher_is_riskier') {
                // v <= low => 0.0000; v >= high => 100.0000
                if (DecimalMath::comp($val,$low, DecimalMath::SCALE_RAW) <= 0) {
                    return '0.0000';
                }
                if (DecimalMath::comp($val,$high, DecimalMath::SCALE_RAW) >= 0) {
                    return '100.0000';
                }

                $range = DecimalMath::sub($high, $low, DecimalMath::SCALE_RAW);$delta = DecimalMath::sub($val,$low, DecimalMath::SCALE_RAW);
                return DecimalMath::mul(DecimalMath::div($delta,$range, DecimalMath::SCALE_RAW), '100.0000', DecimalMath::SCALE_SCORE);
            }

            if ($direction === 'lower_is_riskier') {
                // v >= low => 0.0000; v <= high => 100.0000
                if (DecimalMath::comp($val,$low, DecimalMath::SCALE_RAW) >= 0) {
                    return '0.0000';
                }
                if (DecimalMath::comp($val,$high, DecimalMath::SCALE_RAW) <= 0) {
                    return '100.0000';
                }

                $range = DecimalMath::sub($low, $high, DecimalMath::SCALE_RAW);$delta = DecimalMath::sub($low,$val, DecimalMath::SCALE_RAW);
                return DecimalMath::mul(DecimalMath::div($delta,$range, DecimalMath::SCALE_RAW), '100.0000', DecimalMath::SCALE_SCORE);
            }

            if ($direction === 'distance_from_target_is_riskier') {
                $target = DecimalMath::clean((string)($config['target_value'] ?? '0.00000000'), DecimalMath::SCALE_RAW);
                $maxDev = DecimalMath::clean((string)($config['max_deviation'] ?? '1.00000000'), DecimalMath::SCALE_RAW);

                $diff = DecimalMath::abs(DecimalMath::sub($val, $target, DecimalMath::SCALE_RAW), DecimalMath::SCALE_RAW);$rawScore = DecimalMath::mul(DecimalMath::div($diff,$maxDev, DecimalMath::SCALE_RAW), '100.0000', DecimalMath::SCALE_SCORE);

                return DecimalMath::min('100.0000', $rawScore, DecimalMath::SCALE_SCORE);
            }

            if ($direction === 'outside_band_is_riskier') {
                $safeMin = DecimalMath::clean((string)($config['safe_min'] ?? $low), DecimalMath::SCALE_RAW);$safeMax = DecimalMath::clean((string)($config['safe_max'] ?? $high), DecimalMath::SCALE_RAW);

                if (DecimalMath::comp($val,$safeMin, DecimalMath::SCALE_RAW) >= 0 && DecimalMath::comp($val,$safeMax, DecimalMath::SCALE_RAW) <= 0) {
                    return '0.0000';
                }

                if (DecimalMath::comp($val, $safeMin, DecimalMath::SCALE_RAW) < 0) {$delta = DecimalMath::sub($safeMin,$val, DecimalMath::SCALE_RAW);
                    $range = DecimalMath::sub($safeMin, $low, DecimalMath::SCALE_RAW);$score = DecimalMath::mul(DecimalMath::div($delta,$range, DecimalMath::SCALE_RAW), '100.0000', DecimalMath::SCALE_SCORE);
                    return DecimalMath::min('100.0000', $score, DecimalMath::SCALE_SCORE);
                }

                $delta = DecimalMath::sub($val,$safeMax, DecimalMath::SCALE_RAW);
                $range = DecimalMath::sub($high, $safeMax, DecimalMath::SCALE_RAW);$score = DecimalMath::mul(DecimalMath::div($delta,$range, DecimalMath::SCALE_RAW), '100.0000', DecimalMath::SCALE_SCORE);
                return DecimalMath::min('100.0000', $score, DecimalMath::SCALE_SCORE);
            }
        }

        return '50.0000';
    }

    /**
     * Determines qualitative risk band from a numeric risk score.
     */
    private function determineRiskBand(string $score): string
    {
        if (DecimalMath::comp($score, '25.0000', DecimalMath::SCALE_SCORE) < 0) {
            return 'low';
        }
        if (DecimalMath::comp($score, '50.0000', DecimalMath::SCALE_SCORE) < 0) {
            return 'moderate';
        }
        if (DecimalMath::comp($score, '75.0000', DecimalMath::SCALE_SCORE) < 0) {
            return 'high';
        }
        return 'critical';
    }
}