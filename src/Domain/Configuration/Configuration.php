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

    private readonly string $coverageThreshold;

    private readonly int $minimumEligibleIndicators;

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

        if (
            self::compareDecimals(
                $coverageThreshold,
                '0.0000'
            ) < 0
        ) {
            throw new RuntimeException(
                'Coverage threshold cannot be negative.'
            );
        }

        if (
            self::compareDecimals(
                $coverageThreshold,
                '100.0000'
            ) > 0
        ) {
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

    /**
     * @return array<string, string>
     */
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

            if (
                self::compareDecimals(
                    $weight,
                    '0.0000'
                ) < 0
            ) {
                throw new RuntimeException(
                    "Original weight for {$indicatorKey} cannot be negative."
                );
            }

            $normalized[$indicatorKey] = $weight;
        }

        $scale = self::maximumDecimalScale(
            array_values($normalized)
        );

        $sum = self::formatDecimal(
            '0',
            $scale
        );

        foreach ($normalized as $weight) {
            $sum = bcadd(
                $sum,
                $weight,
                $scale
            );
        }

        $expected = self::formatDecimal(
            '100',
            $scale
        );

        if (
            bccomp(
                $sum,
                $expected,
                $scale
            ) !== 0
        ) {
            throw new RuntimeException(
                "Original weights must sum exactly to 100.0000. Actual: {$sum}"
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, string>>
     */
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
                    self::compareDecimals(
                        $rule['low'],
                        $rule['high']
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
                    self::compareDecimals(
                        $rule['max_deviation'],
                        '0'
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
                    self::compareDecimals(
                        $rule['outside_min'],
                        $rule['safe_min']
                    ) >= 0
                    || self::compareDecimals(
                        $rule['safe_min'],
                        $rule['safe_max']
                    ) >= 0
                    || self::compareDecimals(
                        $rule['safe_max'],
                        $rule['outside_max']
                    ) >= 0
                ) {
                    throw new RuntimeException(
                        "Invalid outside-band boundaries for {$indicatorKey}."
                    );
                }

                break;
        }
    }

    /**
     * @return array<string, string>
     */
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

            $normalized[$frequency] = $discount;
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
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
                self::compareDecimals(
                    $thresholds[$band],
                    '0'
                ) < 0
                || self::compareDecimals(
                    $thresholds[$band],
                    '100'
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

        for (
            $i = 1,
            $count = count($ordered);
            $i < $count;
            $i++
        ) {
            if (
                self::compareDecimals(
                    $thresholds[$ordered[$i - 1]],
                    $thresholds[$ordered[$i]]
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

    /**
     * @param array<int, string> $values
     */
    private static function maximumDecimalScale(
        array $values
    ): int {
        $scale = 0;

        foreach ($values as $value) {
            $scale = max(
                $scale,
                self::decimalScale($value)
            );
        }

        return $scale;
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

    private static function formatDecimal(
        string $value,
        int $scale
    ): string {
        if ($scale === 0) {
            return $value;
        }

        if (strpos($value, '.') === false) {
            return $value . '.' . str_repeat(
                '0',
                $scale
            );
        }

        $currentScale = self::decimalScale(
            $value
        );

        if ($currentScale >= $scale) {
            return $value;
        }

        return $value . str_repeat(
            '0',
            $scale - $currentScale
        );
    }
}