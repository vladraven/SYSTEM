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

        $factor = '1' . str_repeat('0', $scale);
        $negative = $this->isNegative();
        $absolute = $this->absolute()->value;

        $truncated = bcmul(
            $absolute,
            $factor,
            0
        );

        $truncatedValue = bcdiv(
            $truncated,
            $factor,
            $this->scale
        );

        $remainder = bcsub(
            $absolute,
            $truncatedValue,
            $this->scale
        );

        $half = bcdiv(
            '5',
            '1' . str_repeat('0', $scale + 1),
            $this->scale
        );

        if (bccomp($remainder, $half, $this->scale) >= 0) {
            $truncated = bcadd(
                $truncated,
                '1',
                0
            );
        }

        $result = bcdiv(
            $truncated,
            $factor,
            $scale
        );

        if ($negative && bccomp($result, '0', $scale) !== 0) {
            $result = bcmul(
                $result,
                '-1',
                $scale
            );
        }

        return new self(
            $result,
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

        return bcadd(
            $value,
            '0',
            $scale
        );
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