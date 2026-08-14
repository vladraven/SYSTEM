<?php

declare(strict_types=1);

namespace MacroRisk\Engine;

use MacroRisk\Core\Hash\CanonicalHasher;
use MacroRisk\Core\Math\Decimal;
use RuntimeException;

final class RiskEngine
{
    public function calculate(
        array $indicatorConfigs,
        array $snapshotIndicators,
        array $systemConfig,
        array $context = [],
        string $calculationMode = 'production'
    ): array {
        if ($indicatorConfigs === []) {
            throw new RuntimeException('At least one indicator configuration is required.');
        }

        $totalConfiguredWeight = Decimal::score('0.0000');

        foreach ($indicatorConfigs as $config) {
            $totalConfiguredWeight = $totalConfiguredWeight->add(
                Decimal::score((string) ($config['original_weight'] ?? '0.0000'))
            );
        }

        if ($totalConfiguredWeight->compareTo(Decimal::score('100.0000')) !== 0) {
            throw new RuntimeException('Configured original weights must sum exactly to 100.0000.');
        }

        $minCoverage = Decimal::score((string) ($systemConfig['calculation']['min_coverage_ratio'] ?? '60.0000'));
        $minEligibleIndicators = (int) ($systemConfig['calculation']['min_eligible_indicators'] ?? 3);
        $vintageDate = (string) ($context['vintage_date'] ?? gmdate('c'));
        $configurationVersion = (string) ($context['configuration_version'] ?? 'unknown');
        $modelVersion = (string) ($context['model_version'] ?? 'unknown');
        $configurationHash = (string) ($context['configuration_hash'] ?? CanonicalHasher::hash($indicatorConfigs));
        $systemConfigHash = (string) ($context['system_config_hash'] ?? CanonicalHasher::hash($systemConfig));

        $configuredCount = count($indicatorConfigs);
        $eligibleCount = 0;
        $eligibleWeight = Decimal::score('0.0000');
        $requiredMissingKeys = [];
        $noHistoricalKeys = [];
        $eligibleKeys = [];
        $details = [];
        $selectedObservations = [];
        $sourceSeriesIdentifiers = [];

        foreach ($indicatorConfigs as $indicatorKey => $config) {
            $snapshot = $snapshotIndicators[$indicatorKey] ?? [];
            $originalWeightScore = Decimal::score((string) ($config['original_weight'] ?? '0.0000'));
            $originalWeightRaw = $originalWeightScore->withScale(Decimal::SCALE_RAW);
            $frequencyDiscountScore = Decimal::score((string) ($config['frequency_discount'] ?? '1.0000'));
            $frequencyDiscountRaw = $frequencyDiscountScore->withScale(Decimal::SCALE_RAW);
            $isRequired = (bool) ($config['is_required'] ?? false);
            $missingReason = isset($snapshot['missing_reason']) && is_string($snapshot['missing_reason'])
                ? $snapshot['missing_reason']
                : null;
            $status = isset($snapshot['status']) && is_string($snapshot['status'])
                ? $snapshot['status']
                : 'missing';
            $eligible = false;
            $transformedValue = null;
            $observationValue = isset($snapshot['observation_value']) && is_string($snapshot['observation_value'])
                ? Decimal::raw($snapshot['observation_value'])->toString()
                : null;

            if (
                isset($snapshot['value'])
                && is_string($snapshot['value'])
                && $snapshot['value'] !== ''
                && in_array($status, ['ok', 'cached', 'user_override'], true)
            ) {
                $transformedValue = Decimal::raw($snapshot['value'])->toString();
                $eligible = true;
            }

            if ($eligible) {
                $eligibleCount++;
                $eligibleKeys[] = $indicatorKey;
                $eligibleWeight = $eligibleWeight->add($originalWeightScore);
            } elseif ($isRequired) {
                $requiredMissingKeys[] = $indicatorKey;
            }

            if ($missingReason === 'no_historical_data') {
                $noHistoricalKeys[] = $indicatorKey;
            }

            $selectedObservations[$indicatorKey] = $transformedValue;
            $sourceSeriesIdentifiers[$indicatorKey] = [
                'source_key' => (string) ($config['source_key'] ?? ''),
                'source_series_id' => (string) ($config['source_series_id'] ?? ''),
            ];

            $details[$indicatorKey] = [
                'indicator_key' => $indicatorKey,
                'title' => (string) ($config['title'] ?? $indicatorKey),
                'category' => (string) ($config['category'] ?? 'unknown'),
                'classification' => [
                    'observation' => 'Observation',
                    'transformation' => 'Transformation',
                    'model_output' => 'Model Output',
                ],
                'source_key' => (string) ($config['source_key'] ?? ''),
                'source_series_id' => (string) ($config['source_series_id'] ?? ''),
                'source_link' => isset($snapshot['source_link']) && is_string($snapshot['source_link']) ? $snapshot['source_link'] : null,
                'source_series_title' => isset($config['source_series_title']) ? (string) $config['source_series_title'] : null,
                'reference_period' => isset($snapshot['reference_period']) && is_string($snapshot['reference_period']) ? $snapshot['reference_period'] : null,
                'release_time' => isset($snapshot['release_time']) && is_string($snapshot['release_time']) ? $snapshot['release_time'] : null,
                'retrieved_at' => isset($snapshot['retrieved_at']) && is_string($snapshot['retrieved_at']) ? $snapshot['retrieved_at'] : null,
                'observation_value' => $observationValue,
                'transformed_value' => $transformedValue,
                'original_weight' => $originalWeightScore->toString(),
                'frequency_discount' => $frequencyDiscountScore->toString(),
                'effective_weight' => '0.0000',
                'discounted_weight' => '0.00000000',
                'normalized_score' => null,
                'contribution' => null,
                'is_required' => $isRequired,
                'is_eligible' => $eligible,
                'status' => $status,
                'missing_reason' => $eligible ? null : ($missingReason ?? 'not_available'),
                'transformation_method' => isset($config['transformation_method']) ? (string) $config['transformation_method'] : 'identity',
                'transformation_note' => isset($config['transformation_note']) ? (string) $config['transformation_note'] : null,
                'unit' => isset($config['unit']) ? (string) $config['unit'] : null,
                'source_unit' => isset($config['source_unit']) ? (string) $config['source_unit'] : null,
                '_original_weight_raw' => $originalWeightRaw,
                '_frequency_discount_raw' => $frequencyDiscountRaw,
            ];
        }

        $coverageRatio = $eligibleWeight
            ->withScale(Decimal::SCALE_RAW)
            ->divide($totalConfiguredWeight->withScale(Decimal::SCALE_RAW))
            ->multiply(Decimal::raw('100.00000000'))
            ->rounded(Decimal::SCALE_SCORE);

        $status = 'ok';

        if ($eligibleCount === 0 && count($noHistoricalKeys) === $configuredCount) {
            $status = 'missing_no_historical_data';
        } elseif ($eligibleCount < $minEligibleIndicators) {
            $status = 'insufficient_data';
        } elseif ($requiredMissingKeys !== []) {
            $status = 'required_indicator_missing';
        } elseif ($coverageRatio->compareTo($minCoverage) < 0) {
            $status = 'low_coverage';
        }

        $effectiveWeights = [];
        $normalizedScores = [];
        $contributions = [];
        $riskScore = null;
        $riskBand = null;
        $effectiveWeightSum = Decimal::score('0.0000');

        if ($status === 'ok') {
            $discountedWeights = [];
            $discountedWeightSum = Decimal::raw('0.00000000');

            foreach ($eligibleKeys as $indicatorKey) {
                $discounted = $details[$indicatorKey]['_original_weight_raw']
                    ->multiply($details[$indicatorKey]['_frequency_discount_raw']);
                $discountedWeights[$indicatorKey] = $discounted;
                $details[$indicatorKey]['discounted_weight'] = $discounted->toString();
                $discountedWeightSum = $discountedWeightSum->add($discounted);
            }

            $effectiveWeightRaw = [];

            foreach ($eligibleKeys as $indicatorKey) {
                $effectiveWeightRaw[$indicatorKey] = $discountedWeights[$indicatorKey]
                    ->divide($discountedWeightSum)
                    ->multiply(Decimal::raw('100.00000000'));
            }

            $effectiveWeights = $this->reconcileEffectiveWeights($effectiveWeightRaw, $details);
            $riskScoreRaw = Decimal::raw('0.00000000');

            foreach ($eligibleKeys as $indicatorKey) {
                $normalized = $this->normalize(
                    Decimal::raw((string) $details[$indicatorKey]['transformed_value']),
                    $indicatorConfigs[$indicatorKey]
                );
                $effectiveWeight = Decimal::score($effectiveWeights[$indicatorKey]);
                $contribution = $normalized
                    ->withScale(Decimal::SCALE_RAW)
                    ->multiply($effectiveWeight->withScale(Decimal::SCALE_RAW))
                    ->divide(Decimal::raw('100.00000000'))
                    ->rounded(Decimal::SCALE_SCORE);

                $details[$indicatorKey]['effective_weight'] = $effectiveWeight->toString();
                $details[$indicatorKey]['normalized_score'] = $normalized->toString();
                $details[$indicatorKey]['contribution'] = $contribution->toString();
                $effectiveWeightSum = $effectiveWeightSum->add($effectiveWeight);
                $normalizedScores[$indicatorKey] = $normalized->toString();
                $contributions[$indicatorKey] = $contribution->toString();
                $riskScoreRaw = $riskScoreRaw->add($contribution->withScale(Decimal::SCALE_RAW));
            }

            foreach ($indicatorConfigs as $indicatorKey => $_config) {
                $effectiveWeights[$indicatorKey] = $effectiveWeights[$indicatorKey] ?? '0.0000';
                $normalizedScores[$indicatorKey] = $normalizedScores[$indicatorKey] ?? null;
                $contributions[$indicatorKey] = $contributions[$indicatorKey] ?? null;
            }

            $riskScoreDecimal = $riskScoreRaw->rounded(Decimal::SCALE_SCORE);
            $riskScore = $riskScoreDecimal->toString();
            $riskBand = $this->resolveRiskBand($riskScoreDecimal, $systemConfig['risk_bands'] ?? []);
        } else {
            foreach ($indicatorConfigs as $indicatorKey => $_config) {
                $effectiveWeights[$indicatorKey] = '0.0000';
                $normalizedScores[$indicatorKey] = null;
                $contributions[$indicatorKey] = null;
            }
        }

        foreach ($details as $indicatorKey => $detail) {
            unset(
                $details[$indicatorKey]['_original_weight_raw'],
                $details[$indicatorKey]['_frequency_discount_raw']
            );
        }

        $validationStatuses = [
            'weight_sum_valid' => true,
            'coverage_ratio' => $coverageRatio->toString(),
            'coverage_threshold' => $minCoverage->toString(),
            'coverage_passed' => $coverageRatio->compareTo($minCoverage) >= 0,
            'eligible_indicator_count' => $eligibleCount,
            'min_eligible_indicators' => $minEligibleIndicators,
            'eligible_indicator_count_passed' => $eligibleCount >= $minEligibleIndicators,
            'required_indicators_present' => $requiredMissingKeys === [],
            'required_indicator_missing_keys' => $requiredMissingKeys,
            'missing_no_historical_data_keys' => $noHistoricalKeys,
        ];

        $hashPayload = [
            'model_version' => $modelVersion,
            'configuration_version' => $configurationVersion,
            'configuration_hash' => $configurationHash,
            'system_config_hash' => $systemConfigHash,
            'vintage_date' => $vintageDate,
            'calculation_mode' => $calculationMode,
            'selected_observations' => $selectedObservations,
            'source_series_identifiers' => $sourceSeriesIdentifiers,
            'validation_statuses' => $validationStatuses,
            'effective_weights' => $effectiveWeights,
            'normalized_scores' => $normalizedScores,
            'contributions' => $contributions,
            'calculation_status' => $status,
            'risk_score' => $riskScore,
            'risk_band' => $riskBand,
        ];

        $calculationHash = CanonicalHasher::hash($hashPayload);

        return [
            'model_version' => $modelVersion,
            'configuration_version' => $configurationVersion,
            'configuration_hash' => $configurationHash,
            'system_config_hash' => $systemConfigHash,
            'vintage_date' => $vintageDate,
            'calculation_mode' => $calculationMode,
            'calculation_status' => $status,
            'risk_score' => $riskScore,
            'risk_band' => $riskBand,
            'coverage_ratio' => $coverageRatio->toString(),
            'available_indicator_count' => $eligibleCount,
            'eligible_indicator_count' => $eligibleCount,
            'configured_indicator_count' => $configuredCount,
            'required_indicator_missing' => $requiredMissingKeys !== [],
            'required_indicator_missing_keys' => $requiredMissingKeys,
            'selected_observations' => $selectedObservations,
            'source_series_identifiers' => $sourceSeriesIdentifiers,
            'validation_statuses' => $validationStatuses,
            'effective_weights' => $effectiveWeights,
            'normalized_scores' => $normalizedScores,
            'contributions' => $contributions,
            'effective_weights_sum' => $effectiveWeightSum->toString(),
            'indicators' => $details,
            'calculation_hash' => $calculationHash,
        ];
    }

    private function normalize(Decimal $value, array $config): Decimal
    {
        $method = (string) ($config['normalization_method'] ?? 'threshold_linear');
        $direction = (string) ($config['direction_of_deterioration'] ?? 'higher_is_riskier');

        if ($method === 'threshold_linear') {
            $low = Decimal::raw((string) ($config['low_risk_threshold'] ?? '0.00000000'));
            $high = Decimal::raw((string) ($config['high_risk_threshold'] ?? '100.00000000'));

            if ($direction === 'higher_is_riskier') {
                if ($value->compareTo($low) <= 0) {
                    return Decimal::score('0.0000');
                }

                if ($value->compareTo($high) >= 0) {
                    return Decimal::score('100.0000');
                }

                return $value
                    ->subtract($low)
                    ->divide($high->subtract($low))
                    ->multiply(Decimal::raw('100.00000000'))
                    ->rounded(Decimal::SCALE_SCORE);
            }

            if ($direction === 'lower_is_riskier') {
                if ($value->compareTo($low) >= 0) {
                    return Decimal::score('0.0000');
                }

                if ($value->compareTo($high) <= 0) {
                    return Decimal::score('100.0000');
                }

                return $low
                    ->subtract($value)
                    ->divide($low->subtract($high))
                    ->multiply(Decimal::raw('100.00000000'))
                    ->rounded(Decimal::SCALE_SCORE);
            }

            throw new RuntimeException('Unsupported threshold_linear direction: ' . $direction);
        }

        if ($method === 'distance_from_target_is_riskier') {
            $target = Decimal::raw((string) ($config['target_value'] ?? '0.00000000'));
            $maxDeviation = Decimal::raw((string) ($config['max_deviation'] ?? '1.00000000'));
            $score = $value
                ->subtract($target)
                ->absolute()
                ->divide($maxDeviation)
                ->multiply(Decimal::raw('100.00000000'))
                ->rounded(Decimal::SCALE_SCORE);

            return $score->minimum(Decimal::score('100.0000'));
        }

        if ($method === 'outside_band_is_riskier') {
            $outsideMin = Decimal::raw((string) ($config['outside_min'] ?? '0.00000000'));
            $safeMin = Decimal::raw((string) ($config['safe_min'] ?? '0.00000000'));
            $safeMax = Decimal::raw((string) ($config['safe_max'] ?? '0.00000000'));
            $outsideMax = Decimal::raw((string) ($config['outside_max'] ?? '0.00000000'));

            if (
                !($outsideMin->compareTo($safeMin) < 0
                && $safeMin->compareTo($safeMax) < 0
                && $safeMax->compareTo($outsideMax) < 0)
            ) {
                throw new RuntimeException('outside_band_is_riskier requires outside_min < safe_min < safe_max < outside_max.');
            }

            if ($value->compareTo($safeMin) >= 0 && $value->compareTo($safeMax) <= 0) {
                return Decimal::score('0.0000');
            }

            if ($value->compareTo($outsideMin) <= 0 || $value->compareTo($outsideMax) >= 0) {
                return Decimal::score('100.0000');
            }

            if ($value->compareTo($safeMin) < 0) {
                return $safeMin
                    ->subtract($value)
                    ->divide($safeMin->subtract($outsideMin))
                    ->multiply(Decimal::raw('100.00000000'))
                    ->rounded(Decimal::SCALE_SCORE);
            }

            return $value
                ->subtract($safeMax)
                ->divide($outsideMax->subtract($safeMax))
                ->multiply(Decimal::raw('100.00000000'))
                ->rounded(Decimal::SCALE_SCORE);
        }

        throw new RuntimeException('Unsupported normalization method: ' . $method);
    }

    private function reconcileEffectiveWeights(array $rawWeights, array $details): array
    {
        $rounded = [];
        $sum = Decimal::score('0.0000');

        foreach ($rawWeights as $indicatorKey => $weight) {
            $rounded[$indicatorKey] = $weight->rounded(Decimal::SCALE_SCORE)->toString();
            $sum = $sum->add(Decimal::score($rounded[$indicatorKey]));
        }

        $delta = Decimal::score('100.0000')->subtract($sum);

        if ($delta->isZero()) {
            return $rounded;
        }

        $priority = array_keys($rounded);
        usort($priority, function (string $left, string $right) use ($details): int {
            $leftOriginal = Decimal::score((string) $details[$left]['original_weight']);
            $rightOriginal = Decimal::score((string) $details[$right]['original_weight']);
            $compareOriginal = $rightOriginal->compareTo($leftOriginal);

            if ($compareOriginal !== 0) {
                return $compareOriginal;
            }

            $leftDiscounted = Decimal::raw((string) $details[$left]['discounted_weight']);
            $rightDiscounted = Decimal::raw((string) $details[$right]['discounted_weight']);
            $compareDiscounted = $rightDiscounted->compareTo($leftDiscounted);

            if ($compareDiscounted !== 0) {
                return $compareDiscounted;
            }

            return strcmp($left, $right);
        });

        $step = Decimal::score('0.0001');
        $index = 0;

        while (!$delta->isZero()) {
            $indicatorKey = $priority[$index % count($priority)];
            $current = Decimal::score($rounded[$indicatorKey]);

            if ($delta->isPositive()) {
                $current = $current->add($step);
                $delta = $delta->subtract($step);
            } else {
                $current = $current->subtract($step);
                $delta = $delta->add($step);
            }

            $rounded[$indicatorKey] = $current->toString();
            $index++;
        }

        return $rounded;
    }

    private function resolveRiskBand(Decimal $riskScore, array $bands): string
    {
        foreach ($bands as $bandKey => $band) {
            $min = Decimal::score((string) ($band['min'] ?? '0.0000'));
            $max = Decimal::score((string) ($band['max'] ?? '100.0000'));
            $isLast = $bandKey === array_key_last($bands);

            if ($riskScore->compareTo($min) >= 0) {
                $withinUpper = $isLast
                    ? $riskScore->compareTo($max) <= 0
                    : $riskScore->compareTo($max) < 0;

                if ($withinUpper) {
                    return (string) $bandKey;
                }
            }
        }

        throw new RuntimeException('Unable to resolve risk band for score ' . $riskScore->toString());
    }
}
