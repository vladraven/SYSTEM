<?php

declare(strict_types=1);

namespace MacroRisk\Controller;

use MacroRisk\Application\Ingestion\IngestionService;
use MacroRisk\Application\Scenario\ScenarioEngine;
use MacroRisk\Config\SystemPreset;
use MacroRisk\Core\Hash\CanonicalHasher;
use MacroRisk\Core\Http\HttpClient;
use MacroRisk\Core\Security\ScientificIntegrityGuard;
use MacroRisk\Engine\RiskEngine;
use MacroRisk\Infrastructure\Source\BankOfCanada\BankOfCanadaClient;
use MacroRisk\Infrastructure\Source\StatCan\StatCanClient;
use MacroRisk\Infrastructure\Storage\CalculationRepository;
use MacroRisk\Infrastructure\Storage\ConfigurationRepository;
use MacroRisk\Infrastructure\Storage\IndicatorRepository;
use MacroRisk\Infrastructure\Storage\SeriesRepository;
use MacroRisk\Infrastructure\Storage\SnapshotRepository;
use MacroRisk\Repository\RiskScoreRepository;
use Throwable;

final class DashboardController
{
    private readonly ConfigurationRepository $configurationRepository;
    private readonly IndicatorRepository $indicatorRepository;
    private readonly SeriesRepository $seriesRepository;
    private readonly SnapshotRepository $snapshotRepository;
    private readonly RiskScoreRepository $riskScoreRepository;
    private readonly IngestionService $ingestionService;
    private readonly RiskEngine $riskEngine;
    private readonly ScenarioEngine $scenarioEngine;

    public function __construct()
    {
        $this->configurationRepository = new ConfigurationRepository();
        $this->configurationRepository->bootstrapDefaults();
        $this->indicatorRepository = new IndicatorRepository($this->configurationRepository);
        $this->seriesRepository = new SeriesRepository($this->configurationRepository->storageRoot());
        $this->snapshotRepository = new SnapshotRepository($this->configurationRepository->storageRoot());
        $this->riskScoreRepository = new RiskScoreRepository(new CalculationRepository($this->configurationRepository->storageRoot()));
        $http = HttpClient::production();
        $this->riskEngine = new RiskEngine();
        $this->ingestionService = new IngestionService(
            $this->indicatorRepository,
            $this->seriesRepository,
            $this->snapshotRepository,
            $this->configurationRepository,
            new BankOfCanadaClient($http),
            new StatCanClient($http),
            $this->configurationRepository->storageRoot()
        );
        $this->scenarioEngine = new ScenarioEngine(
            $this->riskEngine,
            $this->configurationRepository,
            new ScientificIntegrityGuard()
        );
    }

    public function getDashboardData(): array
    {
        $snapshot = $this->getFreshSnapshot();
        $system = $this->configurationRepository->getSystemConfig();
        $indicators = $this->indicatorRepository->all();
        $modelVersions = $this->configurationRepository->getModelVersionsConfig();
        $calculation = $this->riskEngine->calculate(
            $indicators,
            $snapshot['indicators'],
            $system,
            [
                'vintage_date' => (string) ($snapshot['vintage_date'] ?? gmdate('c')),
                'configuration_version' => (string) ($modelVersions['active_configuration_version'] ?? SystemPreset::DEFAULT_CONFIGURATION_VERSION),
                'model_version' => (string) ($modelVersions['active_model_version'] ?? SystemPreset::DEFAULT_MODEL_VERSION),
                'configuration_hash' => CanonicalHasher::hash($this->configurationRepository->getIndicatorsConfig()),
                'system_config_hash' => CanonicalHasher::hash($system),
            ],
            'production'
        );
        $calculation['score_key'] = $this->riskScoreRepository->saveCalculationResult(
            $calculation,
            1,
            1,
            (string) $snapshot['vintage_date'],
            'production'
        );

        return [
            'system' => $system,
            'model_versions' => $modelVersions,
            'indicators' => $indicators,
            'snapshot' => $snapshot,
            'calculation' => $calculation,
            'scenario_rules' => $this->configurationRepository->getScenarioRulesConfig(),
        ];
    }

    public function getHelpData(): array
    {
        return [
            'system' => $this->configurationRepository->getSystemConfig(),
            'indicators' => $this->indicatorRepository->all(),
            'sources' => $this->configurationRepository->getSourcesConfig(),
            'scenario_rules' => $this->configurationRepository->getScenarioRulesConfig(),
        ];
    }

    public function handleScenario(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->renderJson(['error' => 'Method not allowed'], 405);
            return;
        }

        try {
            $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $overrides = is_array($payload['overrides'] ?? null) ? $payload['overrides'] : [];
            $snapshot = $this->getFreshSnapshot();
            $system = $this->configurationRepository->getSystemConfig();
            $indicators = $this->indicatorRepository->all();
            $modelVersions = $this->configurationRepository->getModelVersionsConfig();
            $result = $this->scenarioEngine->simulate(
                $indicators,
                $snapshot['indicators'],
                $system,
                $overrides,
                [
                    'vintage_date' => (string) ($snapshot['vintage_date'] ?? gmdate('c')),
                    'configuration_version' => (string) ($modelVersions['active_configuration_version'] ?? SystemPreset::DEFAULT_CONFIGURATION_VERSION),
                    'model_version' => (string) ($modelVersions['active_model_version'] ?? SystemPreset::DEFAULT_MODEL_VERSION),
                    'configuration_hash' => CanonicalHasher::hash($this->configurationRepository->getIndicatorsConfig()),
                    'system_config_hash' => CanonicalHasher::hash($system),
                ]
            );
            $this->renderJson($result);
        } catch (Throwable $throwable) {
            $this->renderJson(['error' => $throwable->getMessage()], 500);
        }
    }

    public function handleIngest(): void
    {
        try {
            $snapshot = $this->ingestionService->ingest(true);
            $this->renderJson($snapshot);
        } catch (Throwable $throwable) {
            $this->renderJson(['error' => $throwable->getMessage()], 500);
        }
    }

    public function renderJson(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function getFreshSnapshot(): array
    {
        $latest = $this->snapshotRepository->latest();

        if ($latest !== null && isset($latest['vintage_date']) && is_string($latest['vintage_date'])) {
            try {
                $vintage = new \DateTimeImmutable($latest['vintage_date']);
                if ($vintage->modify('+6 hours') >= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                    return $latest;
                }
            } catch (Throwable) {
            }
        }

        return $this->ingestionService->ingest(false);
    }
}
