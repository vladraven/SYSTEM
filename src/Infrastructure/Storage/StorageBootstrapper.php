<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use MacroRisk\Config\SystemPreset;
use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;

final class StorageBootstrapper
{
    public function __construct(
        private readonly ?string $storageRoot = null
    ) {
    }

    public function bootstrap(): void
    {
        $root = StoragePath::storageRoot($this->storageRoot);

        $directories = [
            $root,
            $root . DIRECTORY_SEPARATOR . 'config',
            $root . DIRECTORY_SEPARATOR . 'raw',
            $root . DIRECTORY_SEPARATOR . 'raw' . DIRECTORY_SEPARATOR . 'statcan',
            $root . DIRECTORY_SEPARATOR . 'raw' . DIRECTORY_SEPARATOR . 'bank_of_canada',
            $root . DIRECTORY_SEPARATOR . 'raw' . DIRECTORY_SEPARATOR . 'open_government',
            $root . DIRECTORY_SEPARATOR . 'series',
            $root . DIRECTORY_SEPARATOR . 'snapshots',
            $root . DIRECTORY_SEPARATOR . 'calculations',
            $root . DIRECTORY_SEPARATOR . 'audit',
            $root . DIRECTORY_SEPARATOR . 'cache',
        ];

        foreach ($directories as $directory) {
            StoragePath::ensureDirectory($directory);
        }

        $configStore = new JsonStore(
            new AtomicJsonFile($root . DIRECTORY_SEPARATOR . 'config')
        );

        $configStore->write('system.json', SystemPreset::defaultSystemConfig());
        $configStore->write('indicators.json', SystemPreset::defaultIndicators());
        $configStore->write('sources.json', SystemPreset::defaultSources());
        $configStore->write('model_versions.json', SystemPreset::defaultModelVersions());
        $configStore->write('scenario_rules.json', SystemPreset::defaultScenarioRules());
    }
}
