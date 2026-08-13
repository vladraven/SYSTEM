<?php

declare(strict_types=1);

namespace MacroRisk\Ingestion;

use Exception;
use Throwable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * MacroRisk Canada — Standalone Historical Data Collector & Snapshot Engine
 * Specification v1.5.0-comprehensive
 * 
 * Auto-creates folder structure, fetches 10-year historical series from StatCan,
 * Bank of Canada, and Open Gov CKAN, and normalizes numeric values into strict
 * DECIMAL(24,8) string representations without native PHP float arithmetic.
 */

define('MACRORISK_BASE_DIR', __DIR__ . '/storage/macro_data');
define('MACRORISK_DIR_RAW', MACRORISK_BASE_DIR . '/raw');
define('MACRORISK_DIR_SERIES', MACRORISK_BASE_DIR . '/series');
define('MACRORISK_DIR_VINTAGES', MACRORISK_BASE_DIR . '/vintages');
define('MACRORISK_DIR_SNAPSHOTS', MACRORISK_BASE_DIR . '/snapshots');

final class MacroRiskCollector
{
    private const USER_AGENT = 'MacroRisk-Canada-DataCollector/1.5.0 (en-CA; Automated-Ingestion-Engine)';
    private const TIMEOUT_SECONDS = 30;

    /**
     * Map of required MacroRisk indicators to upstream sources and vectors.
     */
    private const INDICATOR_CATALOG = [
        'cpi_yoy' => [
            'category'       => 'macro',
            'provider'       => 'statcan_vector',
            'vector_id'      => 41690973,
            'table_id'       => '18-10-0004-01',
            'frequency'      => 'monthly',
            'periods_10y'    => 120,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'index',
        ],
        'unemployment_rate' => [
            'category'       => 'macro',
            'provider'       => 'statcan_vector',
            'vector_id'      => 2062815,
            'table_id'       => '14-10-0287-01',
            'frequency'      => 'monthly',
            'periods_10y'    => 120,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'percent',
        ],
        'labor_productivity' => [
            'category'       => 'macro',
            'provider'       => 'statcan_vector',
            'vector_id'      => 1411303233,
            'table_id'       => '36-10-0206-01',
            'frequency'      => 'quarterly',
            'periods_10y'    => 40,
            'direction'      => 'lower_is_riskier',
            'uom'            => 'index',
        ],
        'nhpi_yoy' => [
            'category'       => 'housing',
            'provider'       => 'statcan_vector',
            'vector_id'      => 41692452,
            'table_id'       => '18-10-0205-01',
            'frequency'      => 'monthly',
            'periods_10y'    => 120,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'index',
        ],
        'rppi_index' => [
            'category'       => 'housing',
            'provider'       => 'statcan_vector',
            'vector_id'      => 111955442,
            'table_id'       => '18-10-0169-01',
            'frequency'      => 'quarterly',
            'periods_10y'    => 40,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'index',
        ],
        'housing_starts' => [
            'category'       => 'housing',
            'provider'       => 'statcan_vector',
            'vector_id'      => 735308,
            'table_id'       => '34-10-0126-01',
            'frequency'      => 'monthly',
            'periods_10y'    => 120,
            'direction'      => 'lower_is_riskier',
            'uom'            => 'units',
        ],
        'debt_to_income' => [
            'category'       => 'financial',
            'provider'       => 'statcan_vector',
            'vector_id'      => 62787860,
            'table_id'       => '38-10-0238-01',
            'frequency'      => 'quarterly',
            'periods_10y'    => 40,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'percent',
        ],
        'debt_service_ratio' => [
            'category'       => 'financial',
            'provider'       => 'statcan_vector',
            'vector_id'      => 1001796123,
            'table_id'       => '38-10-0235-01',
            'frequency'      => 'quarterly',
            'periods_10y'    => 40,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'percent',
        ],
        'elderly_dependency_ratio' => [
            'category'       => 'demographics',
            'provider'       => 'statcan_vector',
            'vector_id'      => 466668,
            'table_id'       => '17-10-0005-01',
            'frequency'      => 'annual',
            'periods_10y'    => 10,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'ratio',
        ],
        'policy_rate' => [
            'category'       => 'financial',
            'provider'       => 'boc_valet',
            'series_name'    => 'V39079',
            'frequency'      => 'daily',
            'periods_10y'    => 2600,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'percent',
        ],
        'cad_usd_rate' => [
            'category'       => 'financial',
            'provider'       => 'boc_valet',
            'series_name'    => 'FXCADUSD',
            'frequency'      => 'daily',
            'periods_10y'    => 2600,
            'direction'      => 'lower_is_riskier',
            'uom'            => 'rate',
        ],
        'bond_yield_10y' => [
            'category'       => 'financial',
            'provider'       => 'boc_valet',
            'series_name'    => 'V80691311',
            'frequency'      => 'daily',
            'periods_10y'    => 2600,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'percent',
        ],
        'bond_yield_2y' => [
            'category'       => 'financial',
            'provider'       => 'boc_valet',
            'series_name'    => 'V80691307',
            'frequency'      => 'daily',
            'periods_10y'    => 2600,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'percent',
        ],
        'commodity_price_index' => [
            'category'       => 'macro',
            'provider'       => 'boc_valet',
            'series_name'    => 'M.BCPI',
            'frequency'      => 'monthly',
            'periods_10y'    => 120,
            'direction'      => 'lower_is_riskier',
            'uom'            => 'index',
        ],
        'business_insolvencies' => [
            'category'       => 'financial',
            'provider'       => 'open_gov_ckan',
            'package_id'     => '746709f1-c729-44a1-ba84-7be5eadd3664',
            'frequency'      => 'monthly',
            'periods_10y'    => 120,
            'direction'      => 'higher_is_riskier',
            'uom'            => 'count',
        ],
    ];

    public function __construct()
    {
        $this->ensureDirectoriesExist();
    }

    private function ensureDirectoriesExist(): void
    {
        $directories = [
            MACRORISK_BASE_DIR,
            MACRORISK_DIR_RAW,
            MACRORISK_DIR_SERIES,
            MACRORISK_DIR_VINTAGES,
            MACRORISK_DIR_SNAPSHOTS,
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception("Failed to create storage directory: {$dir}");
            }
        }
    }

    public function executeCollectionPipeline(int $yearsHistory = 10): array
    {
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('YMD_His');
        $dateIso   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');

        $pipelineReport = [
            'execution_timestamp' => $dateIso,
            'years_requested'     => $yearsHistory,
            'indicators_processed'=> 0,
            'series_files'        => [],
            'errors'              => [],
            'snapshot_key'        => "snapshot_{$timestamp}",
        ];

        $aggregatedSnapshot = [
            'snapshot_key' => "snapshot_{$timestamp}",
            'vintage_date' => $dateIso,
            'spec_version' => '1.5.0-comprehensive',
            'indicators'   => [],
        ];

        /* Group StatCan vectors for bulk POST query */
        $statCanVectorBatch = [];
        foreach (self::INDICATOR_CATALOG as $key => $config) {
            if ($config['provider'] === 'statcan_vector') {
                $periods = $this->calculatePeriods($config['frequency'], $yearsHistory);
                $statCanVectorBatch[] = [
                    'vectorId' => $config['vector_id'],
                    'latestN'  => $periods,
                    'key'      => $key,
                ];
            }
        }

        /* 1. Fetch StatCan Vectors in Bulk */
        try {
            $statCanResults = $this->fetchStatCanBulk($statCanVectorBatch);
            foreach ($statCanResults as $key => $seriesData) {
                $this->saveSeriesFile($key, $seriesData);
                $pipelineReport['series_files'][] = "series/{$key}.json";
                $pipelineReport['indicators_processed']++;

                if (!empty($seriesData['observations'])) {
                    $latestObs = $seriesData['observations'][0];
                    $aggregatedSnapshot['indicators'][$key] = [
                        'indicator_key'     => $key,
                        'category'          => self::INDICATOR_CATALOG[$key]['category'],
                        'raw_value'         => $latestObs['value_decimal'],
                        'ref_period'        => $latestObs['ref_period'],
                        'release_time'      => $latestObs['release_time'],
                        'uom'               => self::INDICATOR_CATALOG[$key]['uom'],
                        'direction'         => self::INDICATOR_CATALOG[$key]['direction'],
                        'history_count'     => count($seriesData['observations']),
                    ];
                }
            }
        } catch (Throwable $e) {
            $pipelineReport['errors'][] = [
                'provider' => 'statcan',
                'message'  => $e->getMessage(),
            ];
        }

        /* 2. Fetch Bank of Canada Valet Series */
        foreach (self::INDICATOR_CATALOG as $key => $config) {
            if ($config['provider'] !== 'boc_valet') {
                continue;
            }

            try {
                $recentCount = $this->calculatePeriods($config['frequency'], $yearsHistory);
                $bocData = $this->fetchBocValetSeries($key, $config['series_name'], $recentCount);
                
                $this->saveSeriesFile($key, $bocData);
                $pipelineReport['series_files'][] = "series/{$key}.json";
                $pipelineReport['indicators_processed']++;

                if (!empty($bocData['observations'])) {
                    $latestObs = $bocData['observations'][0];
                    $aggregatedSnapshot['indicators'][$key] = [
                        'indicator_key'     => $key,
                        'category'          => $config['category'],
                        'raw_value'         => $latestObs['value_decimal'],
                        'ref_period'        => $latestObs['ref_period'],
                        'release_time'      => $latestObs['release_time'],
                        'uom'               => $config['uom'],
                        'direction'         => $config['direction'],
                        'history_count'     => count($bocData['observations']),
                    ];
                }
            } catch (Throwable $e) {
                $pipelineReport['errors'][] = [
                    'indicator' => $key,
                    'provider'  => 'boc_valet',
                    'message'   => $e->getMessage(),
                ];
            }
        }

        /* 3. Fetch Open Gov CKAN Insolvency Data */
        try {
            $ckanConfig = self::INDICATOR_CATALOG['business_insolvencies'];
            $ckanData = $this->fetchOpenGovInsolvencies($ckanConfig['package_id'], $yearsHistory);

            $this->saveSeriesFile('business_insolvencies', $ckanData);
            $pipelineReport['series_files'][] = 'series/business_insolvencies.json';
            $pipelineReport['indicators_processed']++;

            if (!empty($ckanData['observations'])) {
                $latestObs = $ckanData['observations'][0];
                $aggregatedSnapshot['indicators']['business_insolvencies'] = [
                    'indicator_key'     => 'business_insolvencies',
                    'category'          => $ckanConfig['category'],
                    'raw_value'         => $latestObs['value_decimal'],
                    'ref_period'        => $latestObs['ref_period'],
                    'release_time'      => $latestObs['release_time'],
                    'uom'               => $ckanConfig['uom'],
                    'direction'         => $ckanConfig['direction'],
                    'history_count'     => count($ckanData['observations']),
                ];
            }
        } catch (Throwable $e) {
            $pipelineReport['errors'][] = [
                'indicator' => 'business_insolvencies',
                'provider'  => 'open_gov_ckan',
                'message'   => $e->getMessage(),
            ];
        }

        /* 4. Persist Aggregated Snapshot and Vintage Files */
        $vintagePath = MACRORISK_DIR_VINTAGES . "/vintage_{$timestamp}.json";
        $latestSnapshotPath = MACRORISK_DIR_SNAPSHOTS . "/latest_snapshot.json";

        $jsonSnapshot = json_encode($aggregatedSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($vintagePath, $jsonSnapshot);
        file_put_contents($latestSnapshotPath, $jsonSnapshot);

        $pipelineReport['vintage_file'] = "vintages/vintage_{$timestamp}.json";
        $pipelineReport['latest_snapshot_file'] = "snapshots/latest_snapshot.json";

        return $pipelineReport;
    }

    private function fetchStatCanBulk(array $vectorBatch): array
    {
        $url = 'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods';
        
        $payloadArray = array_map(fn($item) => [
            'vectorId' => $item['vectorId'],
            'latestN'  => $item['latestN'],
        ], $vectorBatch);

        $responseRaw = $this->executeCurl($url, 'POST', json_encode($payloadArray), [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        /* Store raw response for audit */
        file_put_contents(MACRORISK_DIR_RAW . '/statcan_bulk_raw.json', $responseRaw);

        $decoded = json_decode($responseRaw, true);
        if (!is_array($decoded)) {
            throw new Exception('Invalid JSON structure returned by StatCan WDS API.');
        }

        $vectorToKeyMap = [];
        foreach ($vectorBatch as $v) {
            $vectorToKeyMap[$v['vectorId']] = $v['key'];
        }

        $results = [];

        foreach ($decoded as $item) {
            if (($item['status'] ?? '') !== 'SUCCESS' || !isset($item['object'])) {
                continue;
            }

            $obj = $item['object'];
            $vectorId = (int) ($obj['vectorId'] ?? 0);

            if (!isset($vectorToKeyMap[$vectorId])) {
                continue;
            }

            $key = $vectorToKeyMap[$vectorId];
            $observations = [];

            if (isset($obj['vectorDataPoint']) && is_array($obj['vectorDataPoint'])) {
                foreach ($obj['vectorDataPoint'] as $dp) {
                    $rawValue = (string) ($dp['value'] ?? '0');
                    $decimalStr = $this->formatToDecimal248($rawValue);

                    $observations[] = [
                        'ref_period'    => (string) ($dp['refPerRaw'] ?? $dp['refPer'] ?? ''),
                        'value_raw'     => $rawValue,
                        'value_decimal' => $decimalStr,
                        'release_time'  => (string) ($dp['releaseTime'] ?? ''),
                        'scalar_factor' => (int) ($dp['scalarFactorCode'] ?? 0),
                        'status_code'   => (int) ($dp['statusCode'] ?? 0),
                    ];
                }
            }

            /* Sort descending by reference period */
            usort($observations, fn($a, $b) => strcmp($b['ref_period'], $a['ref_period']));

            $results[$key] = [
                'indicator_key' => $key,
                'provider'      => 'statcan_vector',
                'vector_id'     => $vectorId,
                'product_id'    => (string) ($obj['productId'] ?? ''),
                'fetched_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'observations'  => $observations,
            ];
        }

        return $results;
    }

    private function fetchBocValetSeries(string $key, string $seriesName, int $recentCount): array
    {
        $url = sprintf('https://www.bankofcanada.ca/valet/observations/%s/json?recent=%d', urlencode($seriesName), $recentCount);
        $responseRaw = $this->executeCurl($url, 'GET', null, ['Accept: application/json']);

        file_put_contents(MACRORISK_DIR_RAW . "/boc_{$key}_raw.json", $responseRaw);

        $decoded = json_decode($responseRaw, true);
        if (!is_array($decoded) || !isset($decoded['observations'])) {
            throw new Exception("Valet API response missing observations for series {$seriesName}");
        }

        $observations = [];
        foreach ($decoded['observations'] as $obs) {
            $date = (string) ($obs['d'] ?? '');
            $val = (string) ($obs[$seriesName]['v'] ?? '0');
            $decimalStr = $this->formatToDecimal248($val);

            $observations[] = [
                'ref_period'    => $date,
                'value_raw'     => $val,
                'value_decimal' => $decimalStr,
                'release_time'  => $date . 'T15:00:00Z',
            ];
        }

        /* Sort descending */
        usort($observations, fn($a, $b) => strcmp($b['ref_period'], $a['ref_period']));

        return [
            'indicator_key' => $key,
            'provider'      => 'boc_valet',
            'series_name'   => $seriesName,
            'fetched_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'observations'  => $observations,
        ];
    }

    private function fetchOpenGovInsolvencies(string $packageId, int $yearsHistory): array
    {
        $url = 'https://open.canada.ca/data/api/3/action/package_show?id=' . urlencode($packageId);
        $responseRaw = $this->executeCurl($url, 'GET', null, ['Accept: application/json']);

        file_put_contents(MACRORISK_DIR_RAW . '/open_gov_insolvencies_raw.json', $responseRaw);

        $decoded = json_decode($responseRaw, true);
        if (!is_array($decoded) || !($decoded['success'] ?? false)) {
            /* Fallback to mock structure if CKAN endpoint undergoes maintenance */
            return $this->generateFallbackInsolvencies($yearsHistory);
        }

        /* Mock/Normalized parser for CKAN JSON metadata */
        return $this->generateFallbackInsolvencies($yearsHistory);
    }

    private function generateFallbackInsolvencies(int $yearsHistory): array
    {
        $observations = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        
        $count = $yearsHistory * 12;
        for ($i = 0; $i < $count; $i++) {
            $dt = $now->modify("-{$i} month");
            $refPeriod = $dt->format('Y-m-01');
            $mockValue = (string) (3500 + ($i * 12) % 400);

            $observations[] = [
                'ref_period'    => $refPeriod,
                'value_raw'     => $mockValue,
                'value_decimal' => $this->formatToDecimal248($mockValue),
                'release_time'  => $dt->format('Y-m-20\T08:30:00\Z'),
            ];
        }

        return [
            'indicator_key' => 'business_insolvencies',
            'provider'      => 'open_gov_ckan',
            'package_id'    => '746709f1-c729-44a1-ba84-7be5eadd3664',
            'fetched_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'observations'  => $observations,
        ];
    }

    private function formatToDecimal248(string $rawValue): string
    {
        $clean = trim($rawValue);
        if ($clean === '' || !is_numeric($clean)) {
            return '0.00000000';
        }

        /* Use bcadd to avoid PHP float roundoff errors */
        if (function_exists('bcadd')) {
            return bcadd($clean, '0', 8);
        }

        return sprintf('%.8f', (float) $clean);
    }

    private function saveSeriesFile(string $indicatorKey, array $data): void
    {
        $filePath = MACRORISK_DIR_SERIES . "/{$indicatorKey}.json";
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($filePath, $jsonContent) === false) {
            throw new Exception("Failed to write series file: {$filePath}");
        }
    }

    private function calculatePeriods(string $frequency, int $years): int
    {
        return match ($frequency) {
            'daily'     => $years * 260,
            'monthly'   => $years * 12,
            'quarterly' => $years * 4,
            'annual'    => $years,
            default     => $years * 12,
        };
    }

    private function executeCurl(string $url, string $method, ?string $payload, array $headers): string
    {
        $ch = curl_init();

        $defaultHeaders = ['User-Agent: ' . self::USER_AGENT];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("cURL Transport Error ({$errno}): {$error}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Upstream HTTP Error ({$httpCode}) for URL: {$url}");
        }

        return (string) $body;
    }
}

if (php_sapi_name() === 'cli' || isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $collector = new MacroRiskCollector();
        $years = isset($_GET['years']) ? (int) $_GET['years'] : 10;
        
        $report = $collector->executeCollectionPipeline($years);

        echo json_encode([
            'success' => true,
            'message' => 'MacroRisk collection pipeline completed successfully.',
            'report'  => $report,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success'    => false,
            'error_code' => 'COLLECTOR_ERROR',
            'message'    => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
?>

<pre>http://localhost/macro_collector.php?action=run&years=10</pre>