<?php

declare(strict_types=1);

namespace MacroRisk\Core\Math;

use DivisionByZeroError;
use InvalidArgumentException;
use RuntimeException;

final class Decimal
{
    public const SCALE_RAW = 8;
    public const SCALE_SCORE = 4;

    private string $value;
    private int $scale;

    private function __construct(string $value, int $scale)
    {
        self::assertScale($scale);

        if (!extension_loaded('bcmath')) {
            throw new RuntimeException('BCMath extension is required.');
        }

        $this->scale = $scale;
        $this->value = self::normalize($value, $scale);
    }

    public static function raw(string $value): self
    {
        return new self($value, self::SCALE_RAW);
    }

    public static function score(string $value): self
    {
        return new self($value, self::SCALE_SCORE);
    }

    public static function of(string $value, int $scale = self::SCALE_RAW): self
    {
        return new self($value, $scale);
    }

    public static function zero(int $scale = self::SCALE_RAW): self
    {
        return new self('0', $scale);
    }

    public static function one(int $scale = self::SCALE_RAW): self
    {
        return new self('1', $scale);
    }

    public function add(self $other): self
    {
        $scale = self::operationScale($this, $other);

        return new self(
            bcadd($this->value, $other->value, $scale),
            $scale
        );
    }

    public function subtract(self $other): self
    {
        $scale = self::operationScale($this, $other);

        return new self(
            bcsub($this->value, $other->value, $scale),
            $scale
        );
    }

    public function multiply(self $other): self
    {
        $scale = self::operationScale($this, $other);

        return new self(
            bcmul($this->value, $other->value, $scale),
            $scale
        );
    }

    public function divide(self $other): self
    {
        $scale = self::operationScale($this, $other);

        if (bccomp($other->value, '0', $scale) === 0) {
            throw new DivisionByZeroError('Decimal division by zero.');
        }

        return new self(
            bcdiv($this->value, $other->value, $scale),
            $scale
        );
    }

    public function absolute(): self
    {
        if (!$this->isNegative()) {
            return $this;
        }

        return new self(
            bcmul($this->value, '-1', $this->scale),
            $this->scale
        );
    }

    public function negate(): self
    {
        return new self(
            bcmul($this->value, '-1', $this->scale),
            $this->scale
        );
    }

    public function minimum(self $other): self
    {
        return $this->compareTo($other) <= 0 ? $this : $other;
    }

    public function maximum(self $other): self
    {
        return $this->compareTo($other) >= 0 ? $this : $other;
    }

    public function compareTo(self $other): int
    {
        $scale = self::operationScale($this, $other);

        return bccomp(
            $this->value,
            $other->value,
            $scale
        );
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', $this->scale) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', $this->scale) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', $this->scale) < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function rounded(int $scale): self
    {
        self::assertScale($scale);

        if ($scale >= $this->scale) {
            return new self(
                $this->value,
                $scale
            );
        }

        return new self(
            self::roundValue($this->value, $scale),
            $scale
        );
    }

    public function withScale(int $scale): self
    {
        self::assertScale($scale);

        return new self(
            $this->value,
            $scale
        );
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function normalize(string $value, int $scale): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                'Decimal value cannot be empty.'
            );
        }

        if (!preg_match(
            '/^[+-]?(?:\d+\.?\d*|\.\d+)$/',
            $value
        )) {
            throw new InvalidArgumentException(
                "Invalid decimal value: {$value}"
            );
        }

        $parts = explode('.', ltrim($value, '+-'), 2);
        $fractionalDigits = isset($parts[1])
            ? strlen($parts[1])
            : 0;

        if ($fractionalDigits <= $scale) {
            return bcadd(
                $value,
                '0',
                $scale
            );
        }

        return self::roundValue($value, $scale);
    }

    private static function roundValue(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '+-');

        $parts = explode('.', $absolute, 2);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        if (strlen($fraction) <= $scale) {
            $normalized = bcadd(
                $absolute,
                '0',
                $scale
            );

            return $negative && $normalized !== '0.' . str_repeat('0', $scale)
                ? bcmul($normalized, '-1', $scale)
                : $normalized;
        }

        $kept = substr($fraction, 0, $scale);
        $discarded = $fraction[$scale] ?? '0';

        $base = $scale === 0
            ? $integer
            : $integer . '.' . $kept;

        $rounded = bcadd(
            $base,
            '0',
            $scale
        );

        if ($discarded >= '5') {
            $increment = $scale === 0
                ? '1'
                : '0.' . str_repeat('0', $scale - 1) . '1';

            $rounded = bcadd(
                $rounded,
                $increment,
                $scale
            );
        }

        if ($negative && bccomp($rounded, '0', $scale) !== 0) {
            $rounded = bcmul(
                $rounded,
                '-1',
                $scale
            );
        }

        return $rounded;
    }

    private static function operationScale(
        self $left,
        self $right
    ): int {
        return max(
            $left->scale,
            $right->scale
        );
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > 64) {
            throw new InvalidArgumentException(
                'Decimal scale must be between 0 and 64.'
            );
        }
    }
}