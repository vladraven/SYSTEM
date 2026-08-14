<?php

declare(strict_types=1);

namespace MacroRisk\Repository;

use MacroRisk\Core\Audit\AuditLogger;
use MacroRisk\Infrastructure\Storage\CalculationRepository;

final class RiskScoreRepository
{
    public function __construct(
        private readonly ?CalculationRepository $calculationRepository = null
    ) {
    }

    public function saveCalculationResult(
        array $calculationResult,
        int $configVersionId = 1,
        int $modelVersionId = 1,
        ?string $vintageDate = null,
        string $calculationMode = 'production',
        ?int $createdBy = null
    ): string {
        $repository = $this->calculationRepository ?? new CalculationRepository();
        $payload = $calculationResult;
        $payload['configuration_version_id'] = $configVersionId;
        $payload['model_version_id'] = $modelVersionId;
        $payload['vintage_date'] = $vintageDate ?? ($calculationResult['vintage_date'] ?? gmdate('c'));
        $payload['calculation_mode'] = $calculationMode;
        $payload['created_by'] = $createdBy;
        $scoreKey = $repository->save($payload);

        AuditLogger::log(
            eventType: 'RISK_SCORE_CALCULATED',
            entityType: 'calculation',
            entityKey: $scoreKey,
            newValue: [
                'risk_score' => $payload['risk_score'] ?? null,
                'risk_band' => $payload['risk_band'] ?? null,
                'calculation_status' => $payload['calculation_status'] ?? null,
                'coverage_ratio' => $payload['coverage_ratio'] ?? null,
            ],
            reason: 'Calculation persisted to JSON storage.',
            actorType: 'system',
            actorUserId: $createdBy
        );

        return $scoreKey;
    }

    public function getResultByScoreKey(string $scoreKey): ?array
    {
        $repository = $this->calculationRepository ?? new CalculationRepository();

        return $repository->find($scoreKey);
    }

    public function latest(): ?array
    {
        $repository = $this->calculationRepository ?? new CalculationRepository();

        return $repository->latest();
    }
}
