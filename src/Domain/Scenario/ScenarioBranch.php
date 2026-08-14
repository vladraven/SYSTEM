<?php

declare(strict_types=1);

namespace MacroRisk\Domain\Scenario;

use MacroRisk\Core\Math\Decimal;
use RuntimeException;

final readonly class ScenarioBranch
{
    public function __construct(
        private string $id,
        private string $name,
        private string $description,
        private string $probabilityWeight,
        private string $timeWindow,
        private array $affectedIndicators
    ) {
        if ($this->id === '' || $this->name === '' || $this->description === '') {
            throw new RuntimeException('Scenario branch identifiers and text cannot be empty.');
        }

        Decimal::score($this->probabilityWeight);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'probability_weight' => Decimal::score($this->probabilityWeight)->toString(),
            'time_window' => $this->timeWindow,
            'affected_indicators' => $this->affectedIndicators,
        ];
    }
}
