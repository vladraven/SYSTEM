<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;

final class SnapshotRepository
{
    private readonly JsonStore $store;

    public function __construct(?string $storageRoot = null)
    {
        $root = StoragePath::storageRoot($storageRoot);
        $this->store = new JsonStore(
            new AtomicJsonFile($root . DIRECTORY_SEPARATOR . 'snapshots')
        );
    }

    public function save(array $snapshot): void
    {
        $vintage = str_replace([':', 'T', 'Z'], ['-', '_', ''], (string) ($snapshot['vintage_date'] ?? 'latest'));
        $this->store->write('latest.json', $snapshot);
        $this->store->write('snapshot_' . $vintage . '.json', $snapshot);
    }

    public function latest(): ?array
    {
        return $this->store->read('latest.json');
    }
}
