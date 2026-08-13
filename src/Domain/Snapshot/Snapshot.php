<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Snapshot;

use MacroRisk\Domain\Observation\Observation;
use RuntimeException;

final class Snapshot
{
    /**
     * @var array<string, Observation>
     */
    private array $observations;

    /**
     * @param array<string, Observation> $observations
     */
    public function __construct(
        private readonly string $snapshotId,
        private readonly string $vintageDate,
        array $observations = []
    ) {
        self::assertIdentifier(
            $snapshotId,
            'snapshot id'
        );

        self::assertDate(
            $vintageDate,
            'vintage date'
        );

        foreach ($observations as $indicatorKey => $observation) {
            if (!is_string($indicatorKey) || $indicatorKey === '') {
                throw new RuntimeException(
                    'Snapshot observation key must be a non-empty string.'
                );
            }

            if (
                preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                    $indicatorKey
                ) !== 1
            ) {
                throw new RuntimeException(
                    "Invalid snapshot indicator key: {$indicatorKey}"
                );
            }

            if (!$observation instanceof Observation) {
                throw new RuntimeException(
                    "Snapshot observation for {$indicatorKey} must be an Observation."
                );
            }

            if (
                $observation->releaseTime()->format('Y-m-d')
                > $vintageDate
            ) {
                throw new RuntimeException(
                    "Observation for {$indicatorKey} was released after snapshot vintage date."
                );
            }
        }

        $this->observations = $observations;
    }

    public function snapshotId(): string
    {
        return $this->snapshotId;
    }

    public function vintageDate(): string
    {
        return $this->vintageDate;
    }

    /**
     * @return array<string, Observation>
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

    public function hasIndicator(
        string $indicatorKey
    ): bool {
        return array_key_exists(
            $indicatorKey,
            $this->observations
        );
    }

    public function observationFor(
        string $indicatorKey
    ): ?Observation {
        return $this->observations[$indicatorKey] ?? null;
    }

    private static function assertIdentifier(
        string $value,
        string $field
    ): void {
        if ($value === '') {
            throw new RuntimeException(
                "Snapshot {$field} cannot be empty."
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid snapshot {$field}: {$value}"
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
                "Invalid snapshot {$field}: {$value}"
            );
        }

        $parts = explode('-', $value);

        if (
            !checkdate(
                (int) $parts[1],
                (int) $parts[2],
                (int) $parts[0]
            )
        ) {
            throw new RuntimeException(
                "Invalid snapshot {$field}: {$value}"
            );
        }
    }
}