<?php

declare(strict_types=1);

namespace MacroRisk\Controller;

use MacroRisk\Engine\RiskEngine;
use MacroRisk\Config\SystemPreset;
use MacroRisk\Repository\RiskScoreRepository;
use MacroRisk\Core\Security\ScientificIntegrityGuard;
use MacroRisk\Core\Audit\AuditLogger;
use MacroRisk\Core\Exceptions\ScientificIntegrityViolationException;
use MacroRisk\DecimalMath;
use Exception;
use Throwable;

/**
 * MacroRisk Dashboard Controller (v1.5.0-comprehensive / Pure PHP Native)
 *
 * Coordinates risk engine calculations, Worked Example verification,
 * data snapshot evaluations, and audit logging.
 */
final class DashboardController
{
    private RiskEngine $riskEngine;
    private RiskScoreRepository $repository;
    private ScientificIntegrityGuard $integrityGuard;

    public function __construct()
    {
        $this->riskEngine = new RiskEngine();
        $this->repository = new RiskScoreRepository();$this->integrityGuard = new ScientificIntegrityGuard();
    }

    /**
     * Executes a risk score calculation for the given snapshot or test fixture observations.
     *
     * @param array<string, string> $observations Custom or snapshot raw indicator values
     * @param int $configVersionId System preset version ID
     * @param string $calculationMode 'production', 'backtest', or 'simulation'
     * @return array<string, mixed> Complete calculation results
     * @throws Exception
     */
    public function calculateRisk(
        array $observations,
        int $configVersionId = SystemPreset::DEFAULT_VERSION_ID,
        string $calculationMode = 'production'
    ): array {
        $preset = SystemPreset::getDefaultPreset();

        // 1. Run Risk Engine calculation
        $result =$this->riskEngine->calculateScore($preset,$observations);

        // 2. Perform Scientific Integrity check on explanation notes
        $explanation = sprintf(
            "MacroRisk score calculated under mode '%s' with coverage ratio %s%%.",
            $calculationMode,$result['coverage_ratio']
        );
        $this->integrityGuard->screen($explanation);

        // 3. Persist calculation results into database
        $scoreKey =$this->repository->saveCalculationResult(
            calculationResult: $result,
            configVersionId: $configVersionId,
            modelVersionId: 1,
            vintageDate: null,
            calculationMode: $calculationMode
        );

        $result['score_key'] =$scoreKey;
        return $result;
    }

    /**
     * Executes and verifies the specification Appendix A Worked Example.
     * Expected Output: Risk Score = 72.3415 (Band: high).
     *
     * @return array<string, mixed> Worked Example verification summary
     */
    public function verifyWorkedExample(): array
    {
        $workedExampleObservations = [
            'debt_service_ratio'    => '15.20000000',
            'housing_starts'        => '4.50000000',
            'bond_yield_10y'        => '-0.50000000',
            'labor_productivity'    => '104.00000000',
            'business_insolvencies' => '25.00000000',
        ];

        $preset = SystemPreset::getDefaultPreset();
        $result =$this->riskEngine->calculateScore($preset,$workedExampleObservations);

        $expectedScore = '72.3415';$isMatch = (DecimalMath::comp((string)$result['risk_score'],$expectedScore, DecimalMath::SCALE_SCORE) === 0);

        return [
            'status'           => $isMatch ? 'PASSED' : 'FAILED',
            'expected_score'   => $expectedScore,
            'calculated_score' => $result['risk_score'],
            'risk_band'        => $result['risk_band'],
            'coverage_ratio'   => $result['coverage_ratio'],
            'contributions'    => $result['contributions'],
            'verified_at'      => date('Y-m-d H:i:s UTC'),
        ];
    }

    /**
     * Renders a JSON response for API endpoints.
     */
    public function renderJson(array $data, int$statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}