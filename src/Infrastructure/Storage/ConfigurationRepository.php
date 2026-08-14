<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;
use RuntimeException;

final class ConfigurationRepository
{
    private readonly string $storageRoot;

    private readonly JsonStore $store;

    public function __construct(?string $storageRoot = null)
    {
        $this->storageRoot = StoragePath::storageRoot($storageRoot);
        $this->store = new JsonStore(
            new AtomicJsonFile($this->storageRoot . DIRECTORY_SEPARATOR . 'config')
        );
    }

    public function bootstrapDefaults(): void
    {
        (new StorageBootstrapper($this->storageRoot))->bootstrap();
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    public function getSystemConfig(): array
    {
        return $this->readRequired('system.json');
    }

    public function getIndicatorsConfig(): array
    {
        return $this->readRequired('indicators.json');
    }

    public function getSourcesConfig(): array
    {
        return $this->readRequired('sources.json');
    }

    public function getModelVersionsConfig(): array
    {
        return $this->readRequired('model_versions.json');
    }

    public function getScenarioRulesConfig(): array
    {
        return $this->readRequired('scenario_rules.json');
    }

    private function readRequired(string $filename): array
    {
        $data = $this->store->read($filename);

        if ($data === null) {
            throw new RuntimeException('Missing configuration file: ' . $filename);
        }

        return $data;
    }
}
