<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Series;

use MacroRisk\Domain\Observation\Observation;
use RuntimeException;

final class Series
{
    private const SCHEMA_VERSION = 1;

    /**
     * @var list<Observation>
     */
    private array $observations;

    /**
     * @param list<Observation> $observations
     */
    public function __construct(
        private readonly string $indicatorKey,
        private readonly string $sourceKey,
        private readonly string $sourceSeriesId,
        private readonly string $title,
        private readonly string $frequency,
        private readonly string $unit,
        array $observations = []
    ) {
        self::assertIdentifier(
            $indicatorKey,
            'indicator key'
        );

        self::assertIdentifier(
            $sourceKey,
            'source key'
        );

        self::assertIdentifier(
            $sourceSeriesId,
            'source series id'
        );

        self::assertText(
            $title,
            'title'
        );

        self::assertFrequency($frequency);

        self::assertText(
            $unit,
            'unit'
        );

        foreach ($observations as $index => $observation) {
            if (!$observation instanceof Observation) {
                throw new RuntimeException(
                    "Series observation at index {$index} must be an Observation."
                );
            }
        }

        $this->observations = array_values($observations);
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    public function indicatorKey(): string
    {
        return $this->indicatorKey;
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function sourceSeriesId(): string
    {
        return $this->sourceSeriesId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function frequency(): string
    {
        return $this->frequency;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    /**
     * @return list<Observation>
     */
    public function observations(): array
    {
        return $this->observations;
    }

    public function observationCount(): int
    {
        return count($this->observations);
    }

    public function isEmpty(): bool
    {
        return $this->observations === [];
    }

    public function hasReferencePeriod(
        string $referencePeriod
    ): bool {
        foreach ($this->observations as $observation) {
            if (
                $observation->referencePeriod()
                === $referencePeriod
            ) {
                return true;
            }
        }

        return false;
    }

    private static function assertIdentifier(
        string $value,
        string $field
    ): void {
        if ($value === '') {
            throw new RuntimeException(
                "Series {$field} cannot be empty."
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid series {$field}: {$value}"
            );
        }
    }

    private static function assertText(
        string $value,
        string $field
    ): void {
        if (trim($value) === '') {
            throw new RuntimeException(
                "Series {$field} cannot be empty."
            );
        }
    }

    private static function assertFrequency(
        string $frequency
    ): void {
        if (
            !in_array(
                $frequency,
                [
                    'daily',
                    'weekly',
                    'monthly',
                    'quarterly',
                    'annual',
                    'irregular',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid series frequency: {$frequency}"
            );
        }
    }
}