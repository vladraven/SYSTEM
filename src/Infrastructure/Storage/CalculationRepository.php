<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use DateTimeImmutable;
use DateTimeZone;
use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;

final class CalculationRepository
{
    private readonly JsonStore $store;

    public function __construct(?string $storageRoot = null)
    {
        $root = StoragePath::storageRoot($storageRoot);
        $this->store = new JsonStore(
            new AtomicJsonFile($root . DIRECTORY_SEPARATOR . 'calculations')
        );
    }

    public function save(array $calculation): string
    {
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $scoreKey = (string) ($calculation['score_key'] ?? sprintf(
            'score_%s_%s',
            $createdAt->format('Ymd_His_u'),
            substr((string) ($calculation['calculation_hash'] ?? 'unknown'), 0, 12)
        ));

        $calculation['score_key'] = $scoreKey;
        $calculation['saved_at'] = $createdAt->format(DATE_ATOM);

        $this->store->write($scoreKey . '.json', $calculation);
        $this->store->write('latest.json', $calculation);

        return $scoreKey;
    }

    public function find(string $scoreKey): ?array
    {
        return $this->store->read($scoreKey . '.json');
    }

    public function latest(): ?array
    {
        return $this->store->read('latest.json');
    }
}
