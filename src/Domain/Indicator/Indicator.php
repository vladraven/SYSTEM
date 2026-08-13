<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Indicator;

use RuntimeException;

final class Indicator
{
    private const CATEGORIES = [
        'financial',
        'housing',
        'macro',
        'labor',
        'demographic',
        'external',
        'other',
    ];

    private const FREQUENCIES = [
        'daily',
        'weekly',
        'monthly',
        'quarterly',
        'annual',
        'irregular',
    ];

    public function __construct(
        private readonly string $indicatorKey,
        private readonly string $category,
        private readonly string $title,
        private readonly string $description,
        private readonly string $unit,
        private readonly string $sourceKey,
        private readonly string $sourceSeriesId,
        private readonly string $frequency,
        private readonly bool $productionAllowed = true
    ) {
        self::assertIdentifier(
            $indicatorKey,
            'indicator key'
        );

        self::assertCategory($category);

        self::assertText(
            $title,
            'title'
        );

        self::assertText(
            $description,
            'description'
        );

        self::assertText(
            $unit,
            'unit'
        );

        self::assertIdentifier(
            $sourceKey,
            'source key'
        );

        self::assertIdentifier(
            $sourceSeriesId,
            'source series id'
        );

        self::assertFrequency($frequency);
    }

    public function indicatorKey(): string
    {
        return $this->indicatorKey;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function sourceSeriesId(): string
    {
        return $this->sourceSeriesId;
    }

    public function frequency(): string
    {
        return $this->frequency;
    }

    public function productionAllowed(): bool
    {
        return $this->productionAllowed;
    }

    private static function assertIdentifier(
        string $value,
        string $field
    ): void {
        if ($value === '') {
            throw new RuntimeException(
                "Indicator {$field} cannot be empty."
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid indicator {$field}: {$value}"
            );
        }
    }

    private static function assertCategory(
        string $category
    ): void {
        if (
            !in_array(
                $category,
                self::CATEGORIES,
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid indicator category: {$category}"
            );
        }
    }

    private static function assertText(
        string $value,
        string $field
    ): void {
        if (trim($value) === '') {
            throw new RuntimeException(
                "Indicator {$field} cannot be empty."
            );
        }
    }

    private static function assertFrequency(
        string $frequency
    ): void {
        if (
            !in_array(
                $frequency,
                self::FREQUENCIES,
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid indicator frequency: {$frequency}"
            );
        }
    }
}