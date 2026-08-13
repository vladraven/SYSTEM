<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Configuration;

use RuntimeException;

final class Configuration
{
    private const RISK_BANDS = [
        'very_low',
        'low',
        'moderate',
        'high',
        'severe',
    ];

    private const NORMALIZATION_METHODS = [
        'threshold_linear',
        'distance_from_target_is_riskier',
        'outside_band_is_riskier',
    ];

    private const DIRECTIONS = [
        'higher_is_riskier',
        'lower_is_riskier',
    ];

    private const FREQUENCIES = [
        'daily',
        'weekly',
        'monthly',
        'quarterly',
        'annual',
        'irregular',
    ];

    private readonly array $originalWeights;
    private readonly array $normalizations;
    private readonly array $frequencyDiscounts;
    private readonly array $riskBandThresholds;

    public function __construct(
        private readonly string $configurationVersion,
        array $originalWeights,
        array $normalizations,
        array $frequencyDiscounts,
        private readonly string $coverageThreshold,
        private readonly int $minimumEligibleIndicators,
        array $riskBandThresholds
    ) {
        self::assertIdentifier(
            $configurationVersion,
            'configuration version'
        );

        $this->originalWeights = self::validateOriginalWeights(
            $originalWeights
        );

        $this->normalizations = self::validateNormalizations(
            $normalizations,
            $this->originalWeights
        );

        $this->frequencyDiscounts = self::validateFrequencyDiscounts(
            $frequencyDiscounts
        );

        self::assertDecimal(
            $coverageThreshold,
            'coverage threshold'
        );

        if (
            self::compareDecimals(
                $coverageThreshold,
                '0'
            ) < 0
            || self::compareDecimals(
                $coverageThreshold,
                '100'
            ) > 0
        ) {
            throw new RuntimeException(
                'Coverage threshold must be between 0.0000 and 100.0000.'
            );
        }

        if ($minimumEligibleIndicators <= 0) {
            throw new RuntimeException(
                'Minimum eligible indicator count must be positive.'
            );
        }

        $this->riskBandThresholds = self::validateRiskBandThresholds(
            $riskBandThresholds
        );
    }

    public function configurationVersion(): string
    {
        return $this->configurationVersion;
    }

    public function coverageThreshold(): string
    {
        return $this->coverageThreshold;
    }

    public function minimumEligibleIndicators(): int
    {
        return $this->minimumEligibleIndicators;
    }

    public function originalWeights(): array
    {
        return $this->originalWeights;
    }

    public function originalWeightFor(
        string $indicatorKey
    ): ?string {
        return $this->originalWeights[$indicatorKey] ?? null;
    }

    public function normalizations(): array
    {
        return $this->normalizations;
    }

    public function normalizationFor(
        string $indicatorKey
    ): ?array {
        return $this->normalizations[$indicatorKey] ?? null;
    }

    public function frequencyDiscounts(): array
    {
        return $this->frequencyDiscounts;
    }

    public function frequencyDiscountFor(
        string $frequency
    ): string {
        return $this->frequencyDiscounts[$frequency] ?? '1.0000';
    }

    public function riskBandThresholds(): array
    {
        return $this->riskBandThresholds;
    }

    public function riskBandThresholdFor(
        string $riskBand
    ): ?string {
        return $this->riskBandThresholds[$riskBand] ?? null;
    }

    private static function validateOriginalWeights(
        array $weights
    ): array {
        if ($weights === []) {
            throw new RuntimeException(
                'Original weights cannot be empty.'
            );
        }

        $validated = [];

        foreach ($weights as $indicatorKey => $weight) {
            self::assertIndicatorKey(
                $indicatorKey
            );

            if (!is_string($weight)) {
                throw new RuntimeException(
                    "Original weight for {$indicatorKey} must be a decimal string."
                );
            }

            self::assertDecimal(
                $weight,
                "Original weight for {$indicatorKey}"
            );

            if (
                self::compareDecimals(
                    $weight,
                    '0'
                ) < 0
            ) {
                throw new RuntimeException(
                    "Original weight for {$indicatorKey} cannot be negative."
                );
            }

            $validated[$indicatorKey] = $weight;
        }

        $sum = '0';

        foreach ($validated as $weight) {
            $sum = bcadd(
                $sum,
                $weight,
                self::decimalScale($weight)
            );
        }

        if (
            self::compareDecimals(
                $sum,
                '100'
            ) !== 0
        ) {
            throw new RuntimeException(
                "Original weights must sum exactly to 100.0000. Actual: {$sum}"
            );
        }

        return $validated;
    }

    private static function validateNormalizations(
        array $normalizations,
        array $originalWeights
    ): array {
        foreach ($originalWeights as $indicatorKey => $_weight) {
            if (!array_key_exists($indicatorKey, $normalizations)) {
                throw new RuntimeException(
                    "Normalization is required for weighted indicator: {$indicatorKey}"
                );
            }
        }

        foreach ($normalizations as $indicatorKey => $normalization) {
            self::assertIndicatorKey(
                $indicatorKey
            );

            if (!array_key_exists($indicatorKey, $originalWeights)) {
                throw new RuntimeException(
                    "Normalization references unknown indicator: {$indicatorKey}"
                );
            }

            if (!is_array($normalization)) {
                throw new RuntimeException(
                    "Normalization for {$indicatorKey} must be an array."
                );
            }

            self::validateNormalization(
                $indicatorKey,
                $normalization
            );
        }

        return $normalizations;
    }

    private static function validateNormalization(
        string $indicatorKey,
        array $normalization
    ): void {
        if (
            !isset($normalization['method'])
            || !is_string($normalization['method'])
        ) {
            throw new RuntimeException(
                "Normalization method is required for {$indicatorKey}."
            );
        }

        $method = $normalization['method'];

        if (
            !in_array(
                $method,
                self::NORMALIZATION_METHODS,
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid normalization method for {$indicatorKey}: {$method}"
            );
        }

        if (
            !isset($normalization['direction'])
            || !is_string($normalization['direction'])
        ) {
            throw new RuntimeException(
                "Normalization direction is required for {$indicatorKey}."
            );
        }

        if (
            !in_array(
                $normalization['direction'],
                self::DIRECTIONS,
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid normalization direction for {$indicatorKey}: {$normalization['direction']}"
            );
        }

        match ($method) {
            'threshold_linear' => self::validateThresholdNormalization(
                $indicatorKey,
                $normalization
            ),
            'distance_from_target_is_riskier' => self::validateDistanceNormalization(
                $indicatorKey,
                $normalization
            ),
            'outside_band_is_riskier' => self::validateOutsideBandNormalization(
                $indicatorKey,
                $normalization
            ),
        };
    }

    private static function validateThresholdNormalization(
        string $indicatorKey,
        array $normalization
    ): void {
        if (
            !array_key_exists('low', $normalization)
            || !array_key_exists('high', $normalization)
        ) {
            throw new RuntimeException(
                "Threshold normalization for {$indicatorKey} requires low and high."
            );
        }

        self::assertDecimal(
            $normalization['low'],
            "Threshold low for {$indicatorKey}"
        );

        self::assertDecimal(
            $normalization['high'],
            "Threshold high for {$indicatorKey}"
        );

        if (
            self::compareDecimals(
                $normalization['low'],
                $normalization['high']
            ) >= 0
        ) {
            throw new RuntimeException(
                "Threshold low must be less than high for {$indicatorKey}."
            );
        }
    }

    private static function validateDistanceNormalization(
        string $indicatorKey,
        array $normalization
    ): void {
        if (
            !array_key_exists('target', $normalization)
            || !array_key_exists('max_deviation', $normalization)
        ) {
            throw new RuntimeException(
                "Distance normalization for {$indicatorKey} requires target and max_deviation."
            );
        }

        self::assertDecimal(
            $normalization['target'],
            "Distance target for {$indicatorKey}"
        );

        self::assertDecimal(
            $normalization['max_deviation'],
            "Distance max deviation for {$indicatorKey}"
        );

        if (
            self::compareDecimals(
                $normalization['max_deviation'],
                '0'
            ) <= 0
        ) {
            throw new RuntimeException(
                "Distance max deviation must be positive for {$indicatorKey}."
            );
        }
    }

    private static function validateOutsideBandNormalization(
        string $indicatorKey,
        array $normalization
    ): void {
        foreach (
            [
                'outside_min',
                'safe_min',
                'safe_max',
                'outside_max',
            ] as $field
        ) {
            if (!array_key_exists($field, $normalization)) {
                throw new RuntimeException(
                    "Outside-band normalization for {$indicatorKey} requires {$field}."
                );
            }

            self::assertDecimal(
                $normalization[$field],
                "Outside-band {$field} for {$indicatorKey}"
            );
        }

        if (
            self::compareDecimals(
                $normalization['outside_min'],
                $normalization['safe_min']
            ) >= 0
            || self::compareDecimals(
                $normalization['safe_min'],
                $normalization['safe_max']
            ) >= 0
            || self::compareDecimals(
                $normalization['safe_max'],
                $normalization['outside_max']
            ) >= 0
        ) {
            throw new RuntimeException(
                "Outside-band boundaries must be strictly increasing for {$indicatorKey}."
            );
        }
    }

    private static function validateFrequencyDiscounts(
        array $discounts
    ): array {
        $validated = [];

        foreach ($discounts as $frequency => $discount) {
            if (
                !is_string($frequency)
                || !in_array(
                    $frequency,
                    self::FREQUENCIES,
                    true
                )
            ) {
                throw new RuntimeException(
                    "Invalid frequency discount key: {$frequency}"
                );
            }

            if (!is_string($discount)) {
                throw new RuntimeException(
                    "Frequency discount for {$frequency} must be a decimal string."
                );
            }

            self::assertDecimal(
                $discount,
                "Frequency discount for {$frequency}"
            );

            if (
                self::compareDecimals(
                    $discount,
                    '0'
                ) < 0
                || self::compareDecimals(
                    $discount,
                    '1'
                ) > 0
            ) {
                throw new RuntimeException(
                    "Frequency discount for {$frequency} must be between 0.0000 and 1.0000."
                );
            }

            $validated[$frequency] = $discount;
        }

        return $validated;
    }

    private static function validateRiskBandThresholds(
        array $thresholds
    ): array {
        foreach (self::RISK_BANDS as $riskBand) {
            if (!array_key_exists($riskBand, $thresholds)) {
                throw new RuntimeException(
                    "Risk band threshold is required for {$riskBand}."
                );
            }

            self::assertDecimal(
                $thresholds[$riskBand],
                "Risk band threshold for {$riskBand}"
            );

            if (
                self::compareDecimals(
                    $thresholds[$riskBand],
                    '0'
                ) < 0
                || self::compareDecimals(
                    $thresholds[$riskBand],
                    '100'
                ) > 0
            ) {
                throw new RuntimeException(
                    "Risk band threshold for {$riskBand} must be between 0.0000 and 100.0000."
                );
            }
        }

        for ($index = 1; $index < count(self::RISK_BANDS); $index++) {
            $previous = self::RISK_BANDS[$index - 1];
            $current = self::RISK_BANDS[$index];

            if (
                self::compareDecimals(
                    $thresholds[$previous],
                    $thresholds[$current]
                ) >= 0
            ) {
                throw new RuntimeException(
                    'Risk band thresholds must be strictly increasing.'
                );
            }
        }

        return $thresholds;
    }

    private static function assertIdentifier(
        string $value,
        string $field
    ): void {
        if (
            $value === ''
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid {$field}: {$value}"
            );
        }
    }

    private static function assertIndicatorKey(
        mixed $value
    ): void {
        if (
            !is_string($value)
            || $value === ''
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid indicator key.'
            );
        }
    }

    private static function assertDecimal(
        mixed $value,
        string $field
    ): void {
        if (
            !is_string($value)
            || preg_match(
                '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "{$field} must be a decimal string."
            );
        }
    }

    private static function compareDecimals(
        string $left,
        string $right
    ): int {
        $scale = max(
            self::decimalScale($left),
            self::decimalScale($right)
        );

        return bccomp(
            $left,
            $right,
            $scale
        );
    }

    private static function decimalScale(
        string $value
    ): int {
        $position = strpos(
            $value,
            '.'
        );

        if ($position === false) {
            return 0;
        }

        return strlen($value) - $position - 1;
    }
}