<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Configuration;

use RuntimeException;

final class Configuration
{
    private const NORMALIZATION_METHODS = [
        'threshold_linear',
        'distance_from_target_is_riskier',
        'outside_band_is_riskier',
    ];

    private const DIRECTIONS = [
        'higher_is_riskier',
        'lower_is_riskier',
    ];

    private const RISK_BANDS = [
        'very_low',
        'low',
        'moderate',
        'high',
        'severe',
    ];

    private array $originalWeights;
    private array $normalizations;
    private array $frequencyDiscounts;
    private array $riskBandThresholds;

    public function __construct(
        private readonly string $configurationVersion,
        array $originalWeights,
        array $normalizations,
        array $frequencyDiscounts,
        string $coverageThreshold = '60.0000',
        int $minimumEligibleIndicators = 3,
        array $riskBandThresholds = []
    ) {
        self::assertIdentifier(
            $configurationVersion,
            'configuration version'
        );

        self::assertDecimal(
            $coverageThreshold,
            'coverage threshold'
        );

        if (bccomp($coverageThreshold, '0.0000', 4) < 0) {
            throw new RuntimeException(
                'Coverage threshold cannot be negative.'
            );
        }

        if (bccomp($coverageThreshold, '100.0000', 4) > 0) {
            throw new RuntimeException(
                'Coverage threshold cannot exceed 100.0000.'
            );
        }

        if ($minimumEligibleIndicators < 1) {
            throw new RuntimeException(
                'Minimum eligible indicators must be positive.'
            );
        }

        $this->originalWeights = self::validateOriginalWeights(
            $originalWeights
        );

        $this->normalizations = self::validateNormalizations(
            $normalizations
        );

        $this->frequencyDiscounts = self::validateFrequencyDiscounts(
            $frequencyDiscounts
        );

        $this->riskBandThresholds = self::validateRiskBandThresholds(
            $riskBandThresholds
        );

        $this->coverageThreshold = $coverageThreshold;
        $this->minimumEligibleIndicators = $minimumEligibleIndicators;
    }

    private readonly string $coverageThreshold;

    private readonly int $minimumEligibleIndicators;

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

    /**
     * @return array<string, string>
     */
    public function originalWeights(): array
    {
        return $this->originalWeights;
    }

    public function originalWeightFor(
        string $indicatorKey
    ): ?string {
        return $this->originalWeights[$indicatorKey] ?? null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function normalizations(): array
    {
        return $this->normalizations;
    }

    /**
     * @return array<string, string>|null
     */
    public function normalizationFor(
        string $indicatorKey
    ): ?array {
        return $this->normalizations[$indicatorKey] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function frequencyDiscounts(): array
    {
        return $this->frequencyDiscounts;
    }

    public function frequencyDiscountFor(
        string $frequency
    ): ?string {
        return $this->frequencyDiscounts[$frequency] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function riskBandThresholds(): array
    {
        return $this->riskBandThresholds;
    }

    public function riskBandThresholdFor(
        string $band
    ): ?string {
        return $this->riskBandThresholds[$band] ?? null;
    }

    private static function validateOriginalWeights(
        array $weights
    ): array {
        if ($weights === []) {
            throw new RuntimeException(
                'Original weights cannot be empty.'
            );
        }

        $normalized = [];

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
                "original weight for {$indicatorKey}"
            );

            if (bccomp($weight, '0.0000', 4) < 0) {
                throw new RuntimeException(
                    "Original weight for {$indicatorKey} cannot be negative."
                );
            }

            $normalized[$indicatorKey] = $weight;
        }

        $sum = '0.0000';

        foreach ($normalized as $weight) {
            $sum = bcadd(
                $sum,
                $weight,
                4
            );
        }

        if (bccomp($sum, '100.0000', 4) !== 0) {
            throw new RuntimeException(
                "Original weights must sum exactly to 100.0000. Actual: {$sum}"
            );
        }

        return $normalized;
    }

    private static function validateNormalizations(
        array $normalizations
    ): array {
        $normalized = [];

        foreach ($normalizations as $indicatorKey => $rule) {
            self::assertIndicatorKey(
                $indicatorKey
            );

            if (!is_array($rule)) {
                throw new RuntimeException(
                    "Normalization rule for {$indicatorKey} must be an array."
                );
            }

            $method = $rule['method'] ?? null;

            if (
                !is_string($method)
                || !in_array(
                    $method,
                    self::NORMALIZATION_METHODS,
                    true
                )
            ) {
                throw new RuntimeException(
                    "Invalid normalization method for {$indicatorKey}."
                );
            }

            $direction = $rule['direction'] ?? null;

            if (
                !is_string($direction)
                || !in_array(
                    $direction,
                    self::DIRECTIONS,
                    true
                )
            ) {
                throw new RuntimeException(
                    "Invalid normalization direction for {$indicatorKey}."
                );
            }

            $validated = [
                'method' => $method,
                'direction' => $direction,
            ];

            foreach (
                [
                    'low',
                    'high',
                    'target',
                    'max_deviation',
                    'safe_min',
                    'safe_max',
                    'outside_min',
                    'outside_max',
                ] as $field
            ) {
                if (!array_key_exists($field, $rule)) {
                    continue;
                }

                if (!is_string($rule[$field])) {
                    throw new RuntimeException(
                        "Normalization {$field} for {$indicatorKey} must be a decimal string."
                    );
                }

                self::assertDecimal(
                    $rule[$field],
                    "normalization {$field} for {$indicatorKey}"
                );

                $validated[$field] = $rule[$field];
            }

            self::validateNormalizationParameters(
                $indicatorKey,
                $validated
            );

            $normalized[$indicatorKey] = $validated;
        }

        return $normalized;
    }

    private static function validateNormalizationParameters(
        string $indicatorKey,
        array $rule
    ): void {
        switch ($rule['method']) {
            case 'threshold_linear':
                foreach (['low', 'high'] as $field) {
                    if (!isset($rule[$field])) {
                        throw new RuntimeException(
                            "Normalization {$field} is required for {$indicatorKey}."
                        );
                    }
                }

                if (
                    bccomp(
                        $rule['low'],
                        $rule['high'],
                        8
                    ) === 0
                ) {
                    throw new RuntimeException(
                        "Normalization low and high cannot be equal for {$indicatorKey}."
                    );
                }

                break;

            case 'distance_from_target_is_riskier':
                foreach (['target', 'max_deviation'] as $field) {
                    if (!isset($rule[$field])) {
                        throw new RuntimeException(
                            "Normalization {$field} is required for {$indicatorKey}."
                        );
                    }
                }

                if (
                    bccomp(
                        $rule['max_deviation'],
                        '0.00000000',
                        8
                    ) <= 0
                ) {
                    throw new RuntimeException(
                        "Normalization max_deviation must be positive for {$indicatorKey}."
                    );
                }

                break;

            case 'outside_band_is_riskier':
                foreach (
                    [
                        'safe_min',
                        'safe_max',
                        'outside_min',
                        'outside_max',
                    ] as $field
                ) {
                    if (!isset($rule[$field])) {
                        throw new RuntimeException(
                            "Normalization {$field} is required for {$indicatorKey}."
                        );
                    }
                }

                if (
                    bccomp(
                        $rule['outside_min'],
                        $rule['safe_min'],
                        8
                    ) >= 0
                    || bccomp(
                        $rule['safe_min'],
                        $rule['safe_max'],
                        8
                    ) >= 0
                    || bccomp(
                        $rule['safe_max'],
                        $rule['outside_max'],
                        8
                    ) >= 0
                ) {
                    throw new RuntimeException(
                        "Invalid outside-band boundaries for {$indicatorKey}."
                    );
                }

                break;
        }
    }

    private static function validateFrequencyDiscounts(
        array $discounts
    ): array {
        $normalized = [];

        foreach ($discounts as $frequency => $discount) {
            if (!is_string($frequency) || $frequency === '') {
                throw new RuntimeException(
                    'Frequency discount key cannot be empty.'
                );
            }

            if (!is_string($discount)) {
                throw new RuntimeException(
                    "Frequency discount for {$frequency} must be a decimal string."
                );
            }

            self::assertDecimal(
                $discount,
                "frequency discount for {$frequency}"
            );

            if (
                bccomp($discount, '0.0000', 4) < 0
                || bccomp($discount, '1.0000', 4) > 0
            ) {
                throw new RuntimeException(
                    "Frequency discount for {$frequency} must be between 0.0000 and 1.0000."
                );
            }

            $normalized[$frequency] = $discount;
        }

        return $normalized;
    }

    private static function validateRiskBandThresholds(
        array $thresholds
    ): array {
        if ($thresholds === []) {
            throw new RuntimeException(
                'Risk band thresholds cannot be empty.'
            );
        }

        foreach (self::RISK_BANDS as $band) {
            if (!array_key_exists($band, $thresholds)) {
                throw new RuntimeException(
                    "Missing risk band threshold: {$band}."
                );
            }

            if (!is_string($thresholds[$band])) {
                throw new RuntimeException(
                    "Risk band threshold for {$band} must be a decimal string."
                );
            }

            self::assertDecimal(
                $thresholds[$band],
                "risk band threshold for {$band}"
            );

            if (
                bccomp(
                    $thresholds[$band],
                    '0.0000',
                    4
                ) < 0
                || bccomp(
                    $thresholds[$band],
                    '100.0000',
                    4
                ) > 0
            ) {
                throw new RuntimeException(
                    "Risk band threshold for {$band} must be between 0.0000 and 100.0000."
                );
            }
        }

        $ordered = [
            'very_low',
            'low',
            'moderate',
            'high',
            'severe',
        ];

        for ($i = 1, $count = count($ordered); $i < $count; $i++) {
            if (
                bccomp(
                    $thresholds[$ordered[$i - 1]],
                    $thresholds[$ordered[$i]],
                    4
                ) >= 0
            ) {
                throw new RuntimeException(
                    'Risk band thresholds must be strictly increasing.'
                );
            }
        }

        return $thresholds;
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
                'Invalid configuration indicator key.'
            );
        }
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

    private static function assertDecimal(
        string $value,
        string $field
    ): void {
        if (
            preg_match(
                '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "{$field} must be a decimal string."
            );
        }
    }
}