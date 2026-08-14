<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MacroRisk\Application\Ingestion\IngestionService;
use MacroRisk\Core\Http\HttpClient;
use MacroRisk\Core\Http\HttpResponse;
use MacroRisk\Core\Http\HttpTransport;
use MacroRisk\Infrastructure\Source\BankOfCanada\BankOfCanadaClient;
use MacroRisk\Infrastructure\Source\StatCan\StatCanClient;
use MacroRisk\Infrastructure\Storage\ConfigurationRepository;
use MacroRisk\Infrastructure\Storage\IndicatorRepository;
use MacroRisk\Infrastructure\Storage\SeriesRepository;
use MacroRisk\Infrastructure\Storage\SnapshotRepository;
use MacroRisk\Infrastructure\Storage\StorageBootstrapper;

final class FixtureTransport implements HttpTransport
{
    public function __construct(private readonly string $fixturesRoot)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $method = strtoupper($method);
        $fixture = match (true) {
            str_contains($url, 'bankofcanada') && str_contains($url, 'V39079') && str_contains($url, '/observations/') => 'boc_observations_V39079.json',
            str_contains($url, 'bankofcanada') && str_contains($url, 'V39079') && str_contains($url, '/series/') => 'boc_series_V39079.json',
            str_contains($url, 'bankofcanada') && str_contains($url, 'V122487') && str_contains($url, '/observations/') => 'boc_observations_V122487.json',
            str_contains($url, 'bankofcanada') && str_contains($url, 'V122487') && str_contains($url, '/series/') => 'boc_series_V122487.json',
            str_contains($url, 'getSeriesInfoFromVector') && $body !== null && str_contains($body, '41690973') => 'statcan_series_41690973.json',
            str_contains($url, 'getDataFromVectorsAndLatestNPeriods') && $body !== null && str_contains($body, '41690973') => 'statcan_latest_41690973.json',
            str_contains($url, 'getSeriesInfoFromVector') && $body !== null && str_contains($body, '2062815') => 'statcan_series_2062815.json',
            str_contains($url, 'getDataFromVectorsAndLatestNPeriods') && $body !== null && str_contains($body, '2062815') => 'statcan_latest_2062815.json',
            str_contains($url, 'getSeriesInfoFromVector') && $body !== null && str_contains($body, '729949') => 'statcan_series_729949.json',
            str_contains($url, 'getDataFromVectorsAndLatestNPeriods') && $body !== null && str_contains($body, '729949') => 'statcan_latest_729949.json',
            default => throw new RuntimeException('No fixture for ' . $method . ' ' . $url . ' body=' . (string) $body),
        };

        return new HttpResponse(200, (string) file_get_contents($this->fixturesRoot . '/' . $fixture));
    }
}

$storageRoot = __DIR__ . '/fixtures/ingestion_runtime/storage';
if (is_dir($storageRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($storageRoot);
}
(new StorageBootstrapper($storageRoot))->bootstrap();
$configurationRepository = new ConfigurationRepository($storageRoot);
$indicatorRepository = new IndicatorRepository($configurationRepository);
$seriesRepository = new SeriesRepository($storageRoot);
$snapshotRepository = new SnapshotRepository($storageRoot);
$transport = new FixtureTransport(__DIR__ . '/fixtures/http');
$http = new HttpClient($transport);
$service = new IngestionService(
    $indicatorRepository,
    $seriesRepository,
    $snapshotRepository,
    $configurationRepository,
    new BankOfCanadaClient($http),
    new StatCanClient($http),
    $storageRoot
);

$result = $service->ingest(true);
assertSameValue(5, count($result['indicators']), 'Fixture ingestion should produce five indicators.');
assertSameValue('2.25000000', $result['indicators']['policy_rate']['value'], 'Policy rate should parse from fixture.');
assertSameValue('178800.00000000', $result['indicators']['housing_starts']['value'], 'Housing starts should be annualized from monthly units.');
assertTrueValue(is_file($storageRoot . '/series/housing_starts.json'), 'Series file should be written.');
assertTrueValue(is_file($storageRoot . '/snapshots/latest.json'), 'Snapshot file should be written.');

echo 'ALL INGESTION TESTS PASSED: 1' . PHP_EOL;
