<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Observation;

use DateTimeImmutable;
use RuntimeException;

final class Observation
{
    public function __construct(
        private readonly string $referencePeriod,
        private readonly string $value,
        private readonly DateTimeImmutable $releaseTime,
        private readonly string $status,
        private readonly string $rawHash
    ) {
        self::assertReferencePeriod($referencePeriod);
        self::assertDecimalString($value);
        self::assertStatus($status);
        self::assertRawHash($rawHash);
    }

    public function referencePeriod(): string
    {
        return $this->referencePeriod;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function releaseTime(): DateTimeImmutable
    {
        return $this->releaseTime;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function rawHash(): string
    {
        return $this->rawHash;
    }

    public function isValid(): bool
    {
        return $this->status === 'valid';
    }

    private static function assertReferencePeriod(
        string $referencePeriod
    ): void {
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $referencePeriod
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid observation reference period: {$referencePeriod}"
            );
        }

        $parts = explode('-', $referencePeriod);

        if (
            !checkdate(
                (int) $parts[1],
                (int) $parts[2],
                (int) $parts[0]
            )
        ) {
            throw new RuntimeException(
                "Invalid observation reference period: {$referencePeriod}"
            );
        }
    }

    private static function assertDecimalString(
        string $value
    ): void {
        if (
            preg_match(
                '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                'Observation value must be a decimal string.'
            );
        }
    }

    private static function assertStatus(
        string $status
    ): void {
        if (
            !in_array(
                $status,
                [
                    'valid',
                    'invalid',
                    'missing',
                    'estimated',
                    'revised',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "Invalid observation status: {$status}"
            );
        }
    }

    private static function assertRawHash(
        string $rawHash
    ): void {
        if (
            preg_match(
                '/^[a-f0-9]{64}$/',
                $rawHash
            ) !== 1
        ) {
            throw new RuntimeException(
                'Observation raw hash must be a SHA-256 hexadecimal string.'
            );
        }
    }
}