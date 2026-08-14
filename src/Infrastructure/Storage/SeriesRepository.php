<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use DateInterval;
use DateTimeImmutable;
use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;

final class SeriesRepository
{
    private readonly string $directory;

    private readonly JsonStore $store;

    public function __construct(?string $storageRoot = null)
    {
        $root = StoragePath::storageRoot($storageRoot);
        $this->directory = $root . DIRECTORY_SEPARATOR . 'series';
        $this->store = new JsonStore(new AtomicJsonFile($this->directory));
    }

    public function save(string $indicatorKey, array $series): void
    {
        $this->store->write($this->filename($indicatorKey), $series);
    }

    public function find(string $indicatorKey): ?array
    {
        return $this->store->read($this->filename($indicatorKey));
    }

    public function latestObservation(string $indicatorKey): ?array
    {
        $series = $this->find($indicatorKey);

        if ($series === null) {
            return null;
        }

        $observations = $series['observations'] ?? [];

        for ($i = count($observations) - 1; $i >= 0; $i--) {
            if (($observations[$i]['transformed_value'] ?? null) !== null) {
                return $observations[$i];
            }
        }

        return null;
    }

    public function isFresh(string $indicatorKey, string $frequency, ?DateTimeImmutable $now = null): bool
    {
        $series = $this->find($indicatorKey);

        if ($series === null || !isset($series['retrieved_at']) || !is_string($series['retrieved_at'])) {
            return false;
        }

        $now ??= new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $retrievedAt = new DateTimeImmutable($series['retrieved_at']);
        $ttl = $frequency === 'daily'
            ? new DateInterval('P3D')
            : new DateInterval('P45D');

        return $retrievedAt->add($ttl) >= $now;
    }

    private function filename(string $indicatorKey): string
    {
        return $indicatorKey . '.json';
    }
}
