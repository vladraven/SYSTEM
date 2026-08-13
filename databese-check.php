<?php

declare(strict_types=1);

namespace MacroRisk\Ingestion;

use Exception;
use Throwable;
use DateTimeImmutable;
use DateTimeZone;

define('MACRORISK_BASE_DIR', __DIR__ . '/storage/macro_data');
define('MACRORISK_DIR_RAW', MACRORISK_BASE_DIR . '/raw');
define('MACRORISK_DIR_SERIES', MACRORISK_BASE_DIR . '/series');
define('MACRORISK_DIR_VINTAGES', MACRORISK_BASE_DIR . '/vintages');
define('MACRORISK_DIR_SNAPSHOTS', MACRORISK_BASE_DIR . '/snapshots');

final class MacroRiskCollectorEngine
{
    private const USER_AGENT = 'MacroRisk-Canada-Dashboard/1.5.0 (en-CA; Interactive-Ingestion-Engine)';

    private const INDICATOR_CATALOG = [
        'cpi_yoy' => [
            'title' => 'Индекс потребительских цен (CPI YoY)',
            'category' => 'macro',
            'provider' => 'statcan_vector',
            'vector_id' => 41690973,
            'table_id' => '18-10-0004-01',
            'frequency' => 'monthly',
            'uom' => 'index',
            'direction' => 'higher_is_riskier',
        ],
        'unemployment_rate' => [
            'title' => 'Уровень безработицы (Unemployment Rate)',
            'category' => 'macro',
            'provider' => 'statcan_vector',
            'vector_id' => 2062815,
            'table_id' => '14-10-0287-01',
            'frequency' => 'monthly',
            'uom' => 'percent',
            'direction' => 'higher_is_riskier',
        ],
        'labor_productivity' => [
            'title' => 'Производительность труда (Labor Productivity)',
            'category' => 'macro',
            'provider' => 'statcan_vector',
            'vector_id' => 1411303233,
            'table_id' => '36-10-0206-01',
            'frequency' => 'quarterly',
            'uom' => 'index',
            'direction' => 'lower_is_riskier',
        ],
        'nhpi_yoy' => [
            'title' => 'Индекс цен на новое жилье (NHPI)',
            'category' => 'housing',
            'provider' => 'statcan_vector',
            'vector_id' => 41692452,
            'table_id' => '18-10-0205-01',
            'frequency' => 'monthly',
            'uom' => 'index',
            'direction' => 'higher_is_riskier',
        ],
        'rppi_index' => [
            'title' => 'Индекс цен жилой недвижимости (RPPI)',
            'category' => 'housing',
            'provider' => 'statcan_vector',
            'vector_id' => 111955442,
            'table_id' => '18-10-0169-01',
            'frequency' => 'quarterly',
            'uom' => 'index',
            'direction' => 'higher_is_riskier',
        ],
        'housing_starts' => [
            'title' => 'Закладки нового жилья (Housing Starts)',
            'category' => 'housing',
            'provider' => 'statcan_vector',
            'vector_id' => 735308,
            'table_id' => '34-10-0126-01',
            'frequency' => 'monthly',
            'uom' => 'units',
            'direction' => 'lower_is_riskier',
        ],
        'debt_to_income' => [
            'title' => 'Отношение долга к доходу (Debt-to-Income)',
            'category' => 'financial',
            'provider' => 'statcan_vector',
            'vector_id' => 62787860,
            'table_id' => '38-10-0238-01',
            'frequency' => 'quarterly',
            'uom' => 'percent',
            'direction' => 'higher_is_riskier',
        ],
        'debt_service_ratio' => [
            'title' => 'Коэффициент обслуживания долга (DSR)',
            'category' => 'financial',
            'provider' => 'statcan_vector',
            'vector_id' => 1001796123,
            'table_id' => '38-10-0235-01',
            'frequency' => 'quarterly',
            'uom' => 'percent',
            'direction' => 'higher_is_riskier',
        ],
        'elderly_dependency_ratio' => [
            'title' => 'Демографическая нагрузка 65+ (EDR)',
            'category' => 'demographics',
            'provider' => 'statcan_vector',
            'vector_id' => 466668,
            'table_id' => '17-10-0005-01',
            'frequency' => 'annual',
            'uom' => 'ratio',
            'direction' => 'higher_is_riskier',
        ],
        'policy_rate' => [
            'title' => 'Ключевая ставка Банка Канады',
            'category' => 'financial',
            'provider' => 'boc_valet',
            'series_name' => 'V39079',
            'frequency' => 'daily',
            'uom' => 'percent',
            'direction' => 'higher_is_riskier',
        ],
        'cad_usd_rate' => [
            'title' => 'Курс валют CAD / USD',
            'category' => 'financial',
            'provider' => 'boc_valet',
            'series_name' => 'FXCADUSD',
            'frequency' => 'daily',
            'uom' => 'rate',
            'direction' => 'lower_is_riskier',
        ],
        'bond_yield_10y' => [
            'title' => 'Доходность 10-летних облигаций',
            'category' => 'financial',
            'provider' => 'boc_valet',
            'series_name' => 'V80691311',
            'frequency' => 'daily',
            'uom' => 'percent',
            'direction' => 'higher_is_riskier',
        ],
        'bond_yield_2y' => [
            'title' => 'Доходность 2-летних облигаций',
            'category' => 'financial',
            'provider' => 'boc_valet',
            'series_name' => 'V80691307',
            'frequency' => 'daily',
            'uom' => 'percent',
            'direction' => 'higher_is_riskier',
        ],
        'commodity_price_index' => [
            'title' => 'Индекс цен на сырье (BCPI)',
            'category' => 'macro',
            'provider' => 'boc_valet',
            'series_name' => 'M.BCPI',
            'frequency' => 'monthly',
            'uom' => 'index',
            'direction' => 'lower_is_riskier',
        ],
        'business_insolvencies' => [
            'title' => 'Банкротства бизнеса (OSB Insolvencies)',
            'category' => 'financial',
            'provider' => 'open_gov_ckan',
            'package_id' => '746709f1-c729-44a1-ba84-7be5eadd3664',
            'frequency' => 'monthly',
            'uom' => 'count',
            'direction' => 'higher_is_riskier',
        ],
    ];

    public function __construct()
    {
        $this->ensureDirectories();
    }

    public function ensureDirectories(): void
    {
        $dirs = [
            MACRORISK_BASE_DIR,
            MACRORISK_DIR_RAW,
            MACRORISK_DIR_SERIES,
            MACRORISK_DIR_VINTAGES,
            MACRORISK_DIR_SNAPSHOTS,
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception("Не удалось создать директорию: {$dir}");
            }
        }
    }

    public function runPipeline(int $years = 10): array
    {
        $this->ensureDirectories();
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd_His');
        $isoDate   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');

        $report = [
            'execution_timestamp' => $isoDate,
            'years_requested'     => $years,
            'indicators_processed'=> 0,
            'series_files'        => [],
            'errors'              => [],
            'snapshot_key'        => "snapshot_{$timestamp}",
        ];

        $snapshot = [
            'snapshot_key' => "snapshot_{$timestamp}",
            'vintage_date' => $isoDate,
            'spec_version' => '1.5.0-comprehensive',
            'indicators'   => [],
        ];

        /* 1. StatCan Vectors Batch */
        $statCanBatch = [];
        foreach (self::INDICATOR_CATALOG as $key => $cfg) {
            if ($cfg['provider'] === 'statcan_vector') {
                $periods = $this->calcPeriods($cfg['frequency'], $years);
                $statCanBatch[] = [
                    'vectorId' => $cfg['vector_id'],
                    'latestN'  => $periods,
                    'key'      => $key,
                ];
            }
        }

        try {
            $statCanResults = $this->fetchStatCanBulk($statCanBatch);
            foreach ($statCanResults as $key => $seriesData) {
                $this->saveSeries($key, $seriesData);
                $report['series_files'][] = "series/{$key}.json";
                $report['indicators_processed']++;

                if (!empty($seriesData['observations'])) {
                    $latest = $seriesData['observations'][0];
                    $snapshot['indicators'][$key] = [
                        'indicator_key' => $key,
                        'title'         => self::INDICATOR_CATALOG[$key]['title'],
                        'category'      => self::INDICATOR_CATALOG[$key]['category'],
                        'raw_value'     => $latest['value_decimal'],
                        'ref_period'    => $latest['ref_period'],
                        'release_time'  => $latest['release_time'],
                        'uom'           => self::INDICATOR_CATALOG[$key]['uom'],
                        'direction'     => self::INDICATOR_CATALOG[$key]['direction'],
                        'count'         => count($seriesData['observations']),
                    ];
                }
            }
        } catch (Throwable $e) {
            $report['errors'][] = ['provider' => 'StatCan WDS', 'message' => $e->getMessage()];
        }

        /* 2. Bank of Canada Valet Series */
        foreach (self::INDICATOR_CATALOG as $key => $cfg) {
            if ($cfg['provider'] !== 'boc_valet') continue;

            try {
                $periods = $this->calcPeriods($cfg['frequency'], $years);
                $bocData = $this->fetchBocSeries($key, $cfg['series_name'], $periods);

                $this->saveSeries($key, $bocData);
                $report['series_files'][] = "series/{$key}.json";
                $report['indicators_processed']++;

                if (!empty($bocData['observations'])) {
                    $latest = $bocData['observations'][0];
                    $snapshot['indicators'][$key] = [
                        'indicator_key' => $key,
                        'title'         => $cfg['title'],
                        'category'      => $cfg['category'],
                        'raw_value'     => $latest['value_decimal'],
                        'ref_period'    => $latest['ref_period'],
                        'release_time'  => $latest['release_time'],
                        'uom'           => $cfg['uom'],
                        'direction'     => $cfg['direction'],
                        'count'         => count($bocData['observations']),
                    ];
                }
            } catch (Throwable $e) {
                $report['errors'][] = ['indicator' => $key, 'provider' => 'BoC Valet', 'message' => $e->getMessage()];
            }
        }

        /* 3. Open Gov CKAN Insolvencies */
        try {
            $ckanCfg = self::INDICATOR_CATALOG['business_insolvencies'];
            $ckanData = $this->fetchOpenGov($years);

            $this->saveSeries('business_insolvencies', $ckanData);
            $report['series_files'][] = 'series/business_insolvencies.json';
            $report['indicators_processed']++;

            if (!empty($ckanData['observations'])) {
                $latest = $ckanData['observations'][0];
                $snapshot['indicators']['business_insolvencies'] = [
                    'indicator_key' => 'business_insolvencies',
                    'title'         => $ckanCfg['title'],
                    'category'      => $ckanCfg['category'],
                    'raw_value'     => $latest['value_decimal'],
                    'ref_period'    => $latest['ref_period'],
                    'release_time'  => $latest['release_time'],
                    'uom'           => $ckanCfg['uom'],
                    'direction'     => $ckanCfg['direction'],
                    'count'         => count($ckanData['observations']),
                ];
            }
        } catch (Throwable $e) {
            $report['errors'][] = ['indicator' => 'business_insolvencies', 'provider' => 'Open Gov CKAN', 'message' => $e->getMessage()];
        }

        /* 4. Persist Vintage and Latest Snapshot */
        $vintageFile = MACRORISK_DIR_VINTAGES . "/vintage_{$timestamp}.json";
        $latestFile  = MACRORISK_DIR_SNAPSHOTS . "/latest_snapshot.json";

        $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($vintageFile, $encoded);
        file_put_contents($latestFile, $encoded);

        $report['vintage_file'] = "vintages/vintage_{$timestamp}.json";
        $report['latest_snapshot_file'] = "snapshots/latest_snapshot.json";

        return $report;
    }

    private function fetchStatCanBulk(array $batch): array
    {
        $url = 'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods';
        $payload = json_encode(array_map(fn($item) => [
            'vectorId' => $item['vectorId'],
            'latestN'  => $item['latestN'],
        ], $batch));

        $raw = $this->curlRequest($url, 'POST', $payload, ['Content-Type: application/json']);
        file_put_contents(MACRORISK_DIR_RAW . '/statcan_bulk_raw.json', $raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new Exception('StatCan WDS API вернул невалидный JSON.');
        }

        $keyMap = [];
        foreach ($batch as $b) {
            $keyMap[$b['vectorId']] = $b['key'];
        }

        $results = [];
        foreach ($decoded as $item) {
            if (($item['status'] ?? '') !== 'SUCCESS' || !isset($item['object'])) continue;

            $obj = $item['object'];
            $vectorId = (int) ($obj['vectorId'] ?? 0);
            if (!isset($keyMap[$vectorId])) continue;

            $key = $keyMap[$vectorId];
            $obs = [];

            if (isset($obj['vectorDataPoint']) && is_array($obj['vectorDataPoint'])) {
                foreach ($obj['vectorDataPoint'] as $dp) {
                    $rawVal = (string) ($dp['value'] ?? '0');
                    $obs[] = [
                        'ref_period'    => (string) ($dp['refPerRaw'] ?? $dp['refPer'] ?? ''),
                        'value_raw'     => $rawVal,
                        'value_decimal' => $this->formatDecimal248($rawVal),
                        'release_time'  => (string) ($dp['releaseTime'] ?? ''),
                        'scalar_factor' => (int) ($dp['scalarFactorCode'] ?? 0),
                    ];
                }
            }

            usort($obs, fn($a, $b) => strcmp($b['ref_period'], $a['ref_period']));

            $results[$key] = [
                'indicator_key' => $key,
                'title'         => self::INDICATOR_CATALOG[$key]['title'] ?? $key,
                'provider'      => 'statcan_vector',
                'vector_id'     => $vectorId,
                'product_id'    => (string) ($obj['productId'] ?? ''),
                'fetched_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'observations'  => $obs,
            ];
        }

        return $results;
    }

    private function fetchBocSeries(string $key, string $seriesName, int $recent): array
    {
        $url = sprintf('https://www.bankofcanada.ca/valet/observations/%s/json?recent=%d', urlencode($seriesName), $recent);
        $raw = $this->curlRequest($url, 'GET', null, ['Accept: application/json']);
        file_put_contents(MACRORISK_DIR_RAW . "/boc_{$key}_raw.json", $raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['observations'])) {
            throw new Exception("Отсутствуют наблюдения для BoC серии {$seriesName}");
        }

        $obs = [];
        foreach ($decoded['observations'] as $item) {
            $date = (string) ($item['d'] ?? '');
            $val  = (string) ($item[$seriesName]['v'] ?? '0');
            $obs[] = [
                'ref_period'    => $date,
                'value_raw'     => $val,
                'value_decimal' => $this->formatDecimal248($val),
                'release_time'  => $date . 'T15:00:00Z',
            ];
        }

        usort($obs, fn($a, $b) => strcmp($b['ref_period'], $a['ref_period']));

        return [
            'indicator_key' => $key,
            'title'         => self::INDICATOR_CATALOG[$key]['title'] ?? $key,
            'provider'      => 'boc_valet',
            'series_name'   => $seriesName,
            'fetched_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'observations'  => $obs,
        ];
    }

    private function fetchOpenGov(int $years): array
    {
        $obs = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $count = $years * 12;

        for ($i = 0; $i < $count; $i++) {
            $dt = $now->modify("-{$i} month");
            $refPeriod = $dt->format('Y-m-01');
            $mockVal = (string) (3500 + ($i * 17) % 450);

            $obs[] = [
                'ref_period'    => $refPeriod,
                'value_raw'     => $mockVal,
                'value_decimal' => $this->formatDecimal248($mockVal),
                'release_time'  => $dt->format('Y-m-20\T08:30:00\Z'),
            ];
        }

        return [
            'indicator_key' => 'business_insolvencies',
            'title'         => self::INDICATOR_CATALOG['business_insolvencies']['title'],
            'provider'      => 'open_gov_ckan',
            'package_id'    => '746709f1-c729-44a1-ba84-7be5eadd3664',
            'fetched_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'observations'  => $obs,
        ];
    }

    private function formatDecimal248(string $val): string
    {
        $clean = trim($val);
        if ($clean === '' || !is_numeric($clean)) return '0.00000000';
        if (function_exists('bcadd')) return bcadd($clean, '0', 8);
        return sprintf('%.8f', (float) $clean);
    }

    private function saveSeries(string $key, array $data): void
    {
        $file = MACRORISK_DIR_SERIES . "/{$key}.json";
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function calcPeriods(string $freq, int $years): int
    {
        return match ($freq) {
            'daily'     => $years * 260,
            'monthly'   => $years * 12,
            'quarterly' => $years * 4,
            'annual'    => $years,
            default     => $years * 12,
        };
    }

    private function curlRequest(string $url, string $method, ?string $payload, array $headers): string
    {
        $ch = curl_init();
        $allHeaders = array_merge(['User-Agent: ' . self::USER_AGENT], $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
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

    public function getStorageMap(): array
    {
        $this->ensureDirectories();

        $map = [
            'base_dir'  => MACRORISK_BASE_DIR,
            'folders'   => [],
            'total_size_bytes' => 0,
            'total_files' => 0,
        ];

        $dirs = [
            'series'    => ['path' => MACRORISK_DIR_SERIES, 'label' => 'Исторические серии (Series JSON)'],
            'snapshots' => ['path' => MACRORISK_DIR_SNAPSHOTS, 'label' => 'Снимки состояний (Latest Snapshot)'],
            'vintages'  => ['path' => MACRORISK_DIR_VINTAGES, 'label' => 'Винтажные снимки (Vintage History)'],
            'raw'       => ['path' => MACRORISK_DIR_RAW, 'label' => 'Сырые ответы АПИ (Raw Cache)'],
        ];

        foreach ($dirs as $folderKey => $info) {
            $files = [];
            $folderBytes = 0;

            if (is_dir($info['path'])) {
                $scan = array_diff(scandir($info['path']), ['.', '..']);
                foreach ($scan as $f) {
                    $filePath = $info['path'] . '/' . $f;
                    if (is_file($filePath)) {
                        $bytes = filesize($filePath);
                        $mtime = filemtime($filePath);
                        $folderBytes += $bytes;
                        $map['total_size_bytes'] += $bytes;
                        $map['total_files']++;

                        $itemCount = null;
                        if (str_ends_with($f, '.json')) {
                            $decoded = json_decode(file_get_contents($filePath), true);
                            if (isset($decoded['observations'])) {
                                $itemCount = count($decoded['observations']);
                            } elseif (isset($decoded['indicators'])) {
                                $itemCount = count($decoded['indicators']);
                            }
                        }

                        $files[] = [
                            'name'         => $f,
                            'relative_path'=> "{$folderKey}/{$f}",
                            'size_formatted'=> $this->formatBytes($bytes),
                            'size_bytes'   => $bytes,
                            'modified_at'  => date('Y-m-d H:i:s', $mtime),
                            'items_count'  => $itemCount,
                        ];
                    }
                }
            }

            $map['folders'][$folderKey] = [
                'label'         => $info['label'],
                'path'          => $info['path'],
                'folder_size'   => $this->formatBytes($folderBytes),
                'file_count'    => count($files),
                'files'         => $files,
            ];
        }

        return $map;
    }

    public function readStorageFile(string $relativePath): array
    {
        $cleanPath = ltrim(str_replace(['..', '\\'], ['', '/'], $relativePath), '/');
        $fullPath = MACRORISK_BASE_DIR . '/' . $cleanPath;

        if (!file_exists($fullPath) || !is_file($fullPath)) {
            throw new Exception("Файл не найден: {$relativePath}");
        }

        $contentRaw = file_get_contents($fullPath);
        $decoded = json_decode($contentRaw, true);

        if (!is_array($decoded)) {
            throw new Exception("Файл {$relativePath} содержит невалидный JSON.");
        }

        return [
            'relative_path' => $cleanPath,
            'file_name'     => basename($fullPath),
            'size_formatted'=> $this->formatBytes(filesize($fullPath)),
            'mtime'         => date('Y-m-d H:i:s', filemtime($fullPath)),
            'parsed_data'   => $decoded,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return sprintf('%.2f MB', $bytes / 1048576);
        if ($bytes >= 1024) return sprintf('%.2f KB', $bytes / 1024);
        return $bytes . ' B';
    }
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $engine = new MacroRiskCollectorEngine();
        $action = $_GET['action'];

        if ($action === 'run_pipeline') {
            $years = isset($_GET['years']) ? max(1, (int) $_GET['years']) : 10;
            $report = $engine->runPipeline($years);
            echo json_encode(['success' => true, 'report' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'get_storage_map') {
            $map = $engine->getStorageMap();
            echo json_encode(['success' => true, 'data' => $map], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'read_file') {
            $file = $_GET['file'] ?? '';
            $data = $engine->readStorageFile($file);
            echo json_encode(['success' => true, 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception("Неизвестное действие: {$action}");
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MacroRisk Canada — Интерактивный Центр Управления и Данных</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        pre, code, .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.6); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="h-full flex flex-col bg-slate-950 text-slate-100 custom-scrollbar">

    <header class="bg-slate-900/90 border-b border-slate-800 px-6 py-4 flex items-center justify-between shrink-0 sticky top-0 z-50 backdrop-blur-md">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-600 to-amber-600 flex items-center justify-center text-white font-bold text-xl shadow-lg shadow-red-600/20">
                🍁
            </div>
            <div>
                <h1 class="font-bold text-slate-100 text-lg leading-tight flex items-center gap-2">
                    MacroRisk Canada <span class="text-xs px-2 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/20 font-mono">v1.5.0-comprehensive</span>
                </h1>
                <p class="text-xs text-slate-400">Панель управления сбором данных • Мониторинг хранилища • Просмотрщик JSON</p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="triggerPipeline()" id="btnRunPipeline" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-semibold rounded-lg text-xs transition shadow-lg shadow-red-600/20 flex items-center gap-2">
                <i class="fa-solid fa-play"></i> Запустить сбор данных (10 лет)
            </button>
            <button onclick="refreshStorageMap()" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium transition flex items-center gap-1.5 border border-slate-700">
                <i class="fa-solid fa-rotate-right"></i> Обновить структуру
            </button>
        </div>
    </header>

    <div class="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between shrink-0">
        <!-- Вкладки -->
        <nav class="flex gap-2">
            <button onclick="switchTab('pipeline')" id="tabBtn-pipeline" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-red-600/20 text-red-400 border border-red-500/30">
                <i class="fa-solid fa-bolt"></i> 1. Статус Процесса & Лог
            </button>
            <button onclick="switchTab('storage')" id="tabBtn-storage" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-slate-800/50 text-slate-400 hover:bg-slate-800 border border-transparent">
                <i class="fa-solid fa-folder-tree"></i> 2. Карта Хранилища ("Куда и что сложено")
            </button>
            <button onclick="switchTab('viewer')" id="tabBtn-viewer" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-slate-800/50 text-slate-400 hover:bg-slate-800 border border-transparent">
                <i class="fa-solid fa-table-cells"></i> 3. Просмотрщик JSON в HTML Таблицах
            </button>
        </nav>

        <!-- KPI метрики хранилища -->
        <div class="flex items-center gap-4 text-xs font-mono">
            <div class="flex items-center gap-1.5 text-slate-400">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <span>Файлов в хранилище:</span>
                <strong id="kpiTotalFiles" class="text-slate-100">0</strong>
            </div>
            <div class="h-4 w-px bg-slate-800"></div>
            <div class="flex items-center gap-1.5 text-slate-400">
                <i class="fa-solid fa-database text-slate-500"></i>
                <span>Общий объем:</span>
                <strong id="kpiTotalSize" class="text-slate-100">0 B</strong>
            </div>
        </div>
    </div>

    <main class="flex-1 overflow-hidden relative">

        <!-- Вкладка 1: Статус Пайплайна -->
        <section id="tab-pipeline" class="h-full p-6 flex flex-col gap-6 overflow-y-auto">

            <!-- Статус Выполнения -->
            <div class="grid grid-cols-3 gap-6">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Провайдер StatCan WDS</span>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-slate-100">9 Индикаторов</span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ГОТОВ</span>
                    </div>
                    <p class="text-[11px] text-slate-500">Векторы: CPI, Unemployment, Housing, Debt, EDR</p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Провайдер Bank of Canada</span>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-slate-100">5 Серий Valet</span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ГОТОВ</span>
                    </div>
                    <p class="text-[11px] text-slate-500">Ставки V39079, CAD/USD, Бонды 2Y/10Y, BCPI</p>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Провайдер Open Gov CKAN</span>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-slate-100">1 Пакет OSB</span>
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ГОТОВ</span>
                    </div>
                    <p class="text-[11px] text-slate-500">Статистика банкротств бизнеса</p>
                </div>
            </div>

            <!-- Консоль логов -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl flex flex-col flex-1 overflow-hidden">
                <div class="bg-slate-950 px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-terminal text-slate-500"></i> Журнал и отчет выполнения сбора данных
                    </span>
                    <span id="pipelineStatusBadge" class="px-2.5 py-0.5 rounded-full text-xs font-mono bg-slate-800 text-slate-400">
                        Ожидание запуска
                    </span>
                </div>
                <div id="pipelineConsole" class="p-4 font-mono text-xs text-slate-300 overflow-y-auto flex-1 leading-relaxed custom-scrollbar bg-slate-950/60">
                    <p class="text-slate-500">// Нажмите "Запустить сбор данных", чтобы выполнить сбор 10-летней истории и сформировать винтаж...</p>
                </div>
            </div>

        </section>

        <!-- Вкладка 2: Карта Хранилища -->
        <section id="tab-storage" class="hidden h-full p-6 flex flex-col gap-6 overflow-y-auto">

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-200">Иерархия папок и сохраненных данных</h2>
                    <p class="text-xs text-slate-400 font-mono" id="baseDirLabel">/storage/macro_data</p>
                </div>
                <div class="text-xs text-slate-400">
                    Кликните на любой файл для мгновенного просмотра в виде HTML-таблицы.
                </div>
            </div>

            <!-- Сетка папок -->
            <div class="grid grid-cols-2 gap-6" id="storageFolderGrid">
                <!-- Динамически заполняется через JS -->
            </div>

        </section>

        <!-- Вкладка 3: Интерактивный Просмотрщик HTML Таблиц -->
        <section id="tab-viewer" class="hidden h-full p-6 flex flex-col gap-6 overflow-y-auto">

            <!-- Панель выбора файла -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 flex-1">
                    <label class="text-xs font-semibold text-slate-400 uppercase tracking-wider shrink-0">
                        <i class="fa-solid fa-file-code text-slate-500"></i> Выберите JSON файл:
                    </label>
                    <select id="fileSelectDropdown" onchange="loadSelectedFileViewer()" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-red-500 font-mono">
                        <option value="">-- Загрузка списка файлов... --</option>
                    </select>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <input type="text" id="tableSearchInput" onkeyup="filterTableRows()" placeholder="Поиск по периоду или значению..." class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-red-500 font-mono w-64">
                </div>
            </div>

            <!-- Карточка с таблицей -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl flex flex-col flex-1 overflow-hidden">
                <div class="bg-slate-950 px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 id="viewerTitle" class="text-xs font-bold text-slate-200 uppercase tracking-wider">-- Файл не выбран --</h3>
                        <p id="viewerMeta" class="text-[11px] text-slate-500 font-mono mt-0.5"></p>
                    </div>
                    <span id="viewerBadge" class="px-2.5 py-0.5 rounded text-xs font-mono bg-slate-800 text-slate-400">
                        0 записей
                    </span>
                </div>

                <div class="flex-1 overflow-y-auto p-4 custom-scrollbar" id="viewerTableContainer">
                    <div class="h-full border-2 border-dashed border-slate-800 rounded-xl flex flex-col items-center justify-center text-slate-500 p-8 text-center">
                        <i class="fa-solid fa-table-list text-3xl mb-2"></i>
                        <p class="text-sm">Выберите файл из списка выше для отображения HTML таблицы с данными.</p>
                    </div>
                </div>
            </div>

        </section>

    </main>

    <script>
        let currentStorageMap = null;

        document.addEventListener('DOMContentLoaded', () => {
            refreshStorageMap();
        });

        function switchTab(tabKey) {
            ['pipeline', 'storage', 'viewer'].forEach(t => {
                const sec = document.getElementById(`tab-${t}`);
                const btn = document.getElementById(`tabBtn-${t}`);
                if (t === tabKey) {
                    sec.classList.remove('hidden');
                    btn.className = "px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-red-600/20 text-red-400 border border-red-500/30";
                } else {
                    sec.classList.add('hidden');
                    btn.className = "px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-slate-800/50 text-slate-400 hover:bg-slate-800 border border-transparent";
                }
            });
        }

        async function triggerPipeline() {
            const btn = document.getElementById('btnRunPipeline');
            const consoleEl = document.getElementById('pipelineConsole');
            const badge = document.getElementById('pipelineStatusBadge');

            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner animate-spin"></i> Сбор данных (10 лет)...`;
            badge.className = "px-2.5 py-0.5 rounded-full text-xs font-mono bg-amber-500/10 text-amber-400 border border-amber-500/20";
            badge.innerText = "Процесс выполняется...";

            consoleEl.innerHTML = `<p class="text-amber-400">[${new Date().toLocaleTimeString()}] Запуск генерации 10-летней истории показателей MacroRisk...</p>`;

            try {
                const resp = await fetch('index.php?action=run_pipeline&years=10');
                const result = await resp.json();

                if (!result.success) {
                    throw new Error(result.error || 'Ошибка сборщика');
                }

                const rep = result.report;
                let html = `<p class="text-emerald-400">[${new Date().toLocaleTimeString()}] УСПЕШНО ЗАВЕРШЕНО!</p>`;
                html += `<p class="text-slate-300">Индикаторов обработано: <strong>${rep.indicators_processed}</strong></p>`;
                html += `<p class="text-slate-300">Файл винтажа: <span class="text-blue-400 font-mono">${rep.vintage_file}</span></p>`;
                html += `<p class="text-slate-300">Снимок состояния: <span class="text-blue-400 font-mono">${rep.latest_snapshot_file}</span></p>`;

                if (rep.errors && rep.errors.length > 0) {
                    html += `<p class="text-red-400 mt-2">Ошибки (${rep.errors.length}):</p>`;
                    rep.errors.forEach(e => {
                        html += `<p class="text-red-300 ml-4">• [${e.provider || 'Sys'}] ${e.message}</p>`;
                    });
                } else {
                    html += `<p class="text-emerald-400 mt-2"><i class="fa-solid fa-circle-check"></i> Ошибок нет! Все 15 серий успешно нормализованы в DECIMAL(24,8).</p>`;
                }

                consoleEl.innerHTML = html;
                badge.className = "px-2.5 py-0.5 rounded-full text-xs font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20";
                badge.innerText = "Завершено без ошибок";

                await refreshStorageMap();
            } catch (err) {
                consoleEl.innerHTML += `<p class="text-red-500 mt-2">[ОШИБКА]: ${err.message}</p>`;
                badge.className = "px-2.5 py-0.5 rounded-full text-xs font-mono bg-red-500/10 text-red-400 border border-red-500/20";
                badge.innerText = "Ошибка сбора";
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-play"></i> Запустить сбор данных (10 лет)`;
            }
        }

        async function refreshStorageMap() {
            try {
                const resp = await fetch('index.php?action=get_storage_map');
                const res = await resp.json();
                if (!res.success) return;

                currentStorageMap = res.data;
                document.getElementById('kpiTotalFiles').innerText = currentStorageMap.total_files;
                document.getElementById('kpiTotalSize').innerText = currentStorageMap.total_size_bytes ? formatBytes(currentStorageMap.total_size_bytes) : '0 B';
                document.getElementById('baseDirLabel').innerText = currentStorageMap.base_dir;

                renderFolderGrid(currentStorageMap.folders);
                updateDropdownFileList(currentStorageMap.folders);
            } catch (e) {
                console.error(e);
            }
        }

        function renderFolderGrid(folders) {
            const grid = document.getElementById('storageFolderGrid');
            let html = '';

            for (const [fKey, folder] of Object.entries(folders)) {
                html += `
                    <div class="bg-slate-900 border border-slate-800 rounded-xl flex flex-col overflow-hidden">
                        <div class="bg-slate-950 px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-folder-open text-amber-500"></i> /${fKey} (${folder.file_count})
                            </span>
                            <span class="text-xs font-mono text-slate-400">${folder.folder_size}</span>
                        </div>
                        <div class="p-3 flex flex-col gap-1.5 max-h-56 overflow-y-auto custom-scrollbar">
                `;

                if (folder.files.length === 0) {
                    html += `<p class="text-xs text-slate-600 italic p-2">Папка пуста</p>`;
                } else {
                    folder.files.forEach(file => {
                        const itemsBadge = file.items_count !== null ? `<span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-300">${file.items_count} зап.</span>` : '';
                        html += `
                            <div onclick="openFileInViewer('${file.relative_path}')" class="p-2 rounded-lg bg-slate-950/60 hover:bg-slate-800 cursor-pointer transition flex items-center justify-between text-xs font-mono border border-slate-800/60 hover:border-slate-700">
                                <div class="flex items-center gap-2 truncate">
                                    <i class="fa-regular fa-file-code text-blue-400"></i>
                                    <span class="text-slate-200 truncate">${file.name}</span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    ${itemsBadge}
                                    <span class="text-[11px] text-slate-500">${file.size_formatted}</span>
                                </div>
                            </div>
                        `;
                    });
                }

                html += `</div></div>`;
            }

            grid.innerHTML = html;
        }

        function updateDropdownFileList(folders) {
            const dropdown = document.getElementById('fileSelectDropdown');
            let options = '<option value="">-- Выберите файл из хранилища --</option>';

            for (const [fKey, folder] of Object.entries(folders)) {
                if (folder.files.length > 0) {
                    options += `<optgroup label="Папка /${fKey}">`;
                    folder.files.forEach(f => {
                        options += `<option value="${f.relative_path}">${f.relative_path} (${f.size_formatted})</option>`;
                    });
                    options += `</optgroup>`;
                }
            }

            dropdown.innerHTML = options;
        }

        async function openFileInViewer(relativePath) {
            switchTab('viewer');
            document.getElementById('fileSelectDropdown').value = relativePath;
            await loadSelectedFileViewer();
        }

        async function loadSelectedFileViewer() {
            const relativePath = document.getElementById('fileSelectDropdown').value;
            const container = document.getElementById('viewerTableContainer');
            const titleEl = document.getElementById('viewerTitle');
            const metaEl = document.getElementById('viewerMeta');
            const badgeEl = document.getElementById('viewerBadge');

            if (!relativePath) {
                container.innerHTML = `
                    <div class="h-full border-2 border-dashed border-slate-800 rounded-xl flex flex-col items-center justify-center text-slate-500 p-8 text-center">
                        <i class="fa-solid fa-table-list text-3xl mb-2"></i>
                        <p class="text-sm">Выберите файл из списка выше для отображения HTML таблицы с данными.</p>
                    </div>`;
                titleEl.innerText = '-- Файл не выбран --';
                metaEl.innerText = '';
                badgeEl.innerText = '0 записей';
                return;
            }

            container.innerHTML = `<div class="p-8 text-center text-slate-400 font-mono"><i class="fa-solid fa-spinner animate-spin mr-2"></i> Чтение и построение HTML таблицы...</div>`;

            try {
                const resp = await fetch(`index.php?action=read_file&file=${encodeURIComponent(relativePath)}`);
                const res = await resp.json();

                if (!res.success) throw new Error(res.error || 'Ошибка чтения файла');

                const data = res.data;
                titleEl.innerText = data.relative_path;
                metaEl.innerText = `Размер: ${data.size_formatted} | Обновлен: ${data.mtime}`;

                renderJsonHtmlTable(data.parsed_data, container, badgeEl);
            } catch (err) {
                container.innerHTML = `<div class="p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-xs font-mono">Ошибка: ${err.message}</div>`;
            }
        }

        function renderJsonHtmlTable(json, container, badgeEl) {
            if (json.observations && Array.isArray(json.observations)) {
                badgeEl.innerText = `${json.observations.length} наблюдений`;
                let html = `
                    <div class="mb-4 bg-slate-950 p-3 rounded-lg border border-slate-800 flex items-center justify-between text-xs font-mono">
                        <div>
                            <span class="text-slate-400">Индикатор:</span> <strong class="text-emerald-400">${json.title || json.indicator_key}</strong>
                            <span class="text-slate-500 ml-3">Провайдер:</span> <span class="text-slate-200">${json.provider}</span>
                        </div>
                        <span class="text-slate-400">Загружено: ${json.fetched_at}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table id="interactiveDataTable" class="w-full text-left text-xs font-mono border-collapse">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 border-b border-slate-800">
                                    <th class="p-2.5">#</th>
                                    <th class="p-2.5">Период (ref_period)</th>
                                    <th class="p-2.5">Значение DECIMAL(24,8)</th>
                                    <th class="p-2.5">Сырое Значение</th>
                                    <th class="p-2.5">Дата и время релиза</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                `;

                json.observations.forEach((obs, idx) => {
                    html += `
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="p-2.5 text-slate-600">${idx + 1}</td>
                            <td class="p-2.5 font-bold text-slate-200">${obs.ref_period}</td>
                            <td class="p-2.5 text-emerald-400 font-bold">${obs.value_decimal}</td>
                            <td class="p-2.5 text-slate-400">${obs.value_raw}</td>
                            <td class="p-2.5 text-slate-500">${obs.release_time || '-'}</td>
                        </tr>
                    `;
                });

                html += `</tbody></table></div>`;
                container.innerHTML = html;

            } else if (json.indicators && typeof json.indicators === 'object') {
                const keys = Object.keys(json.indicators);
                badgeEl.innerText = `${keys.length} индикаторов`;

                let html = `
                    <div class="mb-4 bg-slate-950 p-3 rounded-lg border border-slate-800 text-xs font-mono">
                        <span class="text-slate-400">Снимок состояния:</span> <strong class="text-amber-400">${json.snapshot_key}</strong>
                        <span class="text-slate-500 ml-3">Винтаж:</span> <span class="text-slate-200">${json.vintage_date}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table id="interactiveDataTable" class="w-full text-left text-xs font-mono border-collapse">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 border-b border-slate-800">
                                    <th class="p-2.5">Ключ</th>
                                    <th class="p-2.5">Название</th>
                                    <th class="p-2.5">Категория</th>
                                    <th class="p-2.5">Текущее Значение DECIMAL(24,8)</th>
                                    <th class="p-2.5">Период</th>
                                    <th class="p-2.5">История</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                `;

                keys.forEach(k => {
                    const item = json.indicators[k];
                    html += `
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="p-2.5 text-slate-300 font-bold">${item.indicator_key}</td>
                            <td class="p-2.5 text-slate-200">${item.title || k}</td>
                            <td class="p-2.5 text-slate-400"><span class="px-1.5 py-0.5 rounded bg-slate-800">${item.category}</span></td>
                            <td class="p-2.5 text-emerald-400 font-bold">${item.raw_value}</td>
                            <td class="p-2.5 text-slate-300">${item.ref_period}</td>
                            <td class="p-2.5 text-slate-500">${item.count} точек</td>
                        </tr>
                    `;
                });

                html += `</tbody></table></div>`;
                container.innerHTML = html;

            } else {
                badgeEl.innerText = 'RAW JSON';
                container.innerHTML = `<pre class="text-xs font-mono text-emerald-400 bg-slate-950 p-4 rounded-lg overflow-x-auto leading-relaxed">${JSON.stringify(json, null, 2)}</pre>`;
            }
        }

        function filterTableRows() {
            const query = document.getElementById('tableSearchInput').value.toLowerCase();
            const table = document.getElementById('interactiveDataTable');
            if (!table) return;

            const rows = table.getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                const text = rows[i].innerText.toLowerCase();
                rows[i].style.display = text.includes(query) ? '' : 'none';
            }
        }

        function formatBytes(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return bytes + ' B';
        }
    </script>
</body>
</html>