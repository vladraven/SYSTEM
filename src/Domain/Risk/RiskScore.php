<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Risk;

use RuntimeException;

final class RiskScore
{
    private const STATUSES = [
        'ok',
        'insufficient_data',
        'low_coverage',
        'required_indicator_missing',
        'missing_no_historical_data',
    ];

    private const RISK_BANDS = [
        'very_low',
        'low',
        'moderate',
        'high',
        'severe',
    ];

    private array $effectiveWeights;

    private array $normalizedScores;

    private array $contributions;

    private readonly ?string $riskScore;

    public function __construct(
        private readonly string $modelVersion,
        private readonly string $configurationVersion,
        private readonly string $vintageDate,
        private readonly string $status,
        mixed $riskScore,
        private readonly ?string $riskBand,
        private readonly string $coverageRatio,
        array $effectiveWeights,
        array $normalizedScores,
        array $contributions,
        private readonly string $calculationHash
    ) {
        self::assertIdentifier(
            $modelVersion,
            'model version'
        );

        self::assertIdentifier(
            $configurationVersion,
            'configuration version'
        );

        self::assertDate(
            $vintageDate,
            'vintage date'
        );

        self::assertStatus(
            $status
        );

        self::assertDecimal(
            $coverageRatio,
            'coverage ratio'
        );

        if (
            self::compareDecimals(
                $coverageRatio,
                '0'
            ) < 0
            || self::compareDecimals(
                $coverageRatio,
                '100'
            ) > 0
        ) {
            throw new RuntimeException(
                'Coverage ratio must be between 0 and 100.'
            );
        }

        self::assertCalculationResult(
            $status,
            $riskScore,
            $riskBand
        );

        $this->riskScore = $riskScore;

        $this->effectiveWeights = self::validateDecimalMap(
            $effectiveWeights,
            'effective weight',
            true
        );

        $this->normalizedScores = self::validateDecimalMap(
            $normalizedScores,
            'normalized score',
            true
        );

        $this->contributions = self::validateDecimalMap(
            $contributions,
            'contribution',
            false
        );

        self::assertCalculationHash(
            $calculationHash
        );

        self::assertMapKeysMatch(
            $this->effectiveWeights,
            $this->normalizedScores,
            'effective weights',
            'normalized scores'
        );

        self::assertMapKeysMatch(
            $this->effectiveWeights,
            $this->contributions,
            'effective weights',
            'contributions'
        );
    }

    public function modelVersion(): string
    {
        return $this->modelVersion;
    }

    public function configurationVersion(): string
    {
        return $this->configurationVersion;
    }

    public function vintageDate(): string
    {
        return $this->vintageDate;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function riskScore(): ?string
    {
        return $this->riskScore;
    }

    public function riskBand(): ?string
    {
        return $this->riskBand;
    }

    public function coverageRatio(): string
    {
        return $this->coverageRatio;
    }

    public function effectiveWeights(): array
    {
        return $this->effectiveWeights;
    }

    public function effectiveWeightFor(
        string $indicatorKey
    ): ?string {
        return $this->effectiveWeights[$indicatorKey] ?? null;
    }

    public function normalizedScores(): array
    {
        return $this->normalizedScores;
    }

    public function normalizedScoreFor(
        string $indicatorKey
    ): ?string {
        return $this->normalizedScores[$indicatorKey] ?? null;
    }

    public function contributions(): array
    {
        return $this->contributions;
    }

    public function contributionFor(
        string $indicatorKey
    ): ?string {
        return $this->contributions[$indicatorKey] ?? null;
    }

    public function calculationHash(): string
    {
        return $this->calculationHash;
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'ok';
    }

    public function hasRiskResult(): bool
    {
        return $this->riskScore !== null
            && $this->riskBand !== null;
    }

    private static function assertCalculationResult(
        string $status,
        mixed $riskScore,
        ?string $riskBand
    ): void {
        if ($status === 'ok') {
            if ($riskScore === null) {
                throw new RuntimeException(
                    'Successful calculation requires a risk score.'
                );
            }

            if (!is_string($riskScore)) {
                throw new RuntimeException(
                    'Risk score must be a decimal string.'
                );
            }

            if ($riskBand === null) {
                throw new RuntimeException(
                    'Successful calculation requires a risk band.'
                );
            }

            self::assertDecimal(
                $riskScore,
                'risk score'
            );

            if (
                self::compareDecimals(
                    $riskScore,
                    '0'
                ) < 0
                || self::compareDecimals(
                    $riskScore,
                    '100'
                ) > 0
            ) {
                throw new RuntimeException(
                    'Risk score must be between 0 and 100.'
                );
            }

            if (
                !in_array(
                    $riskBand,
                    self::RISK_BANDS,
                    true
                )
            ) {
                throw new RuntimeException(
                    "Invalid risk band: {$riskBand}"
                );
            }

            return;
        }

        if (
            $riskScore !== null
            || $riskBand !== null
        ) {
            throw new RuntimeException(
                'Non-ok calculation must have null risk score and risk band.'
            );
        }
    }

    private static function assertStatus(
        string $status
    ): void {
        if (
            !in_array(
                $status,
                self::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid calculation status: {$status}"
            );
        }
    }

    private static function validateDecimalMap(
        array $values,
        string $field,
        bool $nonNegative
    ): array {
        $normalized = [];

        foreach ($values as $indicatorKey => $value) {
            self::assertIndicatorKey(
                $indicatorKey
            );

            if (!is_string($value)) {
                throw new RuntimeException(
                    "{$field} for {$indicatorKey} must be a decimal string."
                );
            }

            self::assertDecimal(
                $value,
                "{$field} for {$indicatorKey}"
            );

            if (
                $nonNegative
                && self::compareDecimals(
                    $value,
                    '0'
                ) < 0
            ) {
                throw new RuntimeException(
                    "{$field} for {$indicatorKey} cannot be negative."
                );
            }

            $normalized[$indicatorKey] = $value;
        }

        return $normalized;
    }

    private static function assertMapKeysMatch(
        array $first,
        array $second,
        string $firstName,
        string $secondName
    ): void {
        $firstKeys = array_keys(
            $first
        );

        $secondKeys = array_keys(
            $second
        );

        sort(
            $firstKeys,
            SORT_STRING
        );

        sort(
            $secondKeys,
            SORT_STRING
        );

        if ($firstKeys !== $secondKeys) {
            throw new RuntimeException(
                "{$firstName} and {$secondName} must contain identical indicator keys."
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
                'Invalid risk indicator key.'
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

    private static function assertDate(
        string $value,
        string $field
    ): void {
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid {$field}: {$value}"
            );
        }

        $parts = explode(
            '-',
            $value
        );

        if (
            !checkdate(
                (int) $parts[1],
                (int) $parts[2],
                (int) $parts[0]
            )
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

    private static function assertCalculationHash(
        string $hash
    ): void {
        if (
            preg_match(
                '/^[a-f0-9]{64}$/',
                $hash
            ) !== 1
        ) {
            throw new RuntimeException(
                'Calculation hash must be a SHA-256 hexadecimal string.'
            );
        }
    }
}