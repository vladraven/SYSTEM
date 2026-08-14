<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use MacroRisk\Config\SystemPreset;

final class IndicatorRepository
{
    public function __construct(
        private readonly ConfigurationRepository $configurationRepository
    ) {
    }

    public function all(): array
    {
        $config = $this->configurationRepository->getIndicatorsConfig();
        $indicators = $config['indicators'] ?? [];

        SystemPreset::validateIndicatorWeightSum($indicators);

        return $indicators;
    }

    public function find(string $indicatorKey): ?array
    {
        $all = $this->all();

        return $all[$indicatorKey] ?? null;
    }
}
