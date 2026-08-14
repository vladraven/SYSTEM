<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Scenario;

use MacroRisk\Config\SystemPreset;

final readonly class ScenarioRule
{
    /**
     * @param list<ScenarioBranch> $branches
     */
    public function __construct(
        private string $id,
        private string $triggerIndicator,
        private string $triggerDirection,
        private string $triggerThreshold,
        private string $classification,
        private string $disclaimer,
        private string $methodologyNote,
        private array $branches
    ) {
        SystemPreset::validateScenarioProbabilitySums([
            [
                'id' => $this->id,
                'branches' => array_map(
                    static fn (ScenarioBranch $branch): array => $branch->toArray(),
                    $this->branches
                ),
            ],
        ]);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'trigger_indicator' => $this->triggerIndicator,
            'trigger_direction' => $this->triggerDirection,
            'trigger_threshold' => $this->triggerThreshold,
            'classification' => $this->classification,
            'disclaimer' => $this->disclaimer,
            'methodology_note' => $this->methodologyNote,
            'branches' => array_map(
                static fn (ScenarioBranch $branch): array => $branch->toArray(),
                $this->branches
            ),
        ];
    }
}
