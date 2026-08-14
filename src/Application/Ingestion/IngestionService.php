<?php

declare(strict_types=1);

namespace MacroRisk\Application\Ingestion;

use DateTimeImmutable;
use DateTimeZone;
use MacroRisk\Core\Audit\AuditLogger;
use MacroRisk\Core\Math\Decimal;
use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;
use MacroRisk\Infrastructure\Source\BankOfCanada\BankOfCanadaClient;
use MacroRisk\Infrastructure\Source\StatCan\StatCanClient;
use MacroRisk\Infrastructure\Storage\ConfigurationRepository;
use MacroRisk\Infrastructure\Storage\IndicatorRepository;
use MacroRisk\Infrastructure\Storage\SeriesRepository;
use MacroRisk\Infrastructure\Storage\SnapshotRepository;
use MacroRisk\Infrastructure\Storage\StoragePath;
use Throwable;

final class IngestionService
{
    private readonly string $storageRoot;

    public function __construct(
        private readonly IndicatorRepository $indicatorRepository,
        private readonly SeriesRepository $seriesRepository,
        private readonly SnapshotRepository $snapshotRepository,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly BankOfCanadaClient $bankOfCanadaClient,
        private readonly StatCanClient $statCanClient,
        ?string $storageRoot = null
    ) {
        $this->storageRoot = StoragePath::storageRoot($storageRoot ?? $this->configurationRepository->storageRoot());
    }

    public function ingest(bool $force = false): array
    {
        $this->configurationRepository->bootstrapDefaults();
        $indicators = $this->indicatorRepository->all();
        $snapshotIndicators = [];
        $failures = [];
        $ingested = 0;
        $cached = 0;
        $failed = 0;

        foreach ($indicators as $indicatorKey => $config) {
            if (!$force && $this->seriesRepository->isFresh($indicatorKey, (string) ($config['frequency'] ?? 'monthly'))) {
                $series = $this->seriesRepository->find($indicatorKey);

                if ($series !== null) {
                    $snapshotIndicators[$indicatorKey] = $this->seriesToSnapshotIndicator($series);
                    $snapshotIndicators[$indicatorKey]['status'] = 'cached';
                    $cached++;
                    continue;
                }
            }

            try {
                $series = $this->fetchSeries($indicatorKey, $config);
                $this->seriesRepository->save($indicatorKey, $series);
                $snapshotIndicators[$indicatorKey] = $this->seriesToSnapshotIndicator($series);
                $ingested++;
            } catch (Throwable $throwable) {
                $failed++;
                $failures[$indicatorKey] = $throwable->getMessage();
                $series = $this->failedSeries($indicatorKey, $config, $throwable->getMessage());
                $this->seriesRepository->save($indicatorKey, $series);
                $snapshotIndicators[$indicatorKey] = $this->seriesToSnapshotIndicator($series);
            }
        }

        $snapshot = [
            'schema_version' => 1,
            'classification' => 'Observation',
            'vintage_date' => gmdate('c'),
            'ingestion_summary' => [
                'ingested' => $ingested,
                'cached' => $cached,
                'failed' => $failed,
                'failures' => $failures,
            ],
            'indicators' => $snapshotIndicators,
        ];

        $this->snapshotRepository->save($snapshot);
        AuditLogger::log(
            eventType: 'INGESTION_COMPLETED',
            entityType: 'snapshot',
            entityKey: 'latest',
            newValue: $snapshot['ingestion_summary'],
            reason: 'Deterministic ingestion pipeline completed.',
            storageRoot: $this->storageRoot
        );

        return $snapshot;
    }

    private function fetchSeries(string $indicatorKey, array $config): array
    {
        $sourceKey = (string) ($config['source_key'] ?? '');

        return match ($sourceKey) {
            'bank_of_canada' => $this->fetchBankOfCanadaSeries($indicatorKey, $config),
            'statcan_wds' => $this->fetchStatCanSeries($indicatorKey, $config),
            default => throw new \RuntimeException('Unsupported source key: ' . $sourceKey),
        };
    }

    private function fetchBankOfCanadaSeries(string $indicatorKey, array $config): array
    {
        $seriesId = (string) ($config['source_series_id'] ?? '');
        $startDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-18 months')
            ->format('Y-m-d');
        $response = $this->bankOfCanadaClient->getObservations($seriesId, $startDate);
        $metadata = $this->bankOfCanadaClient->getSeries($seriesId);
        $rawPayload = [
            'schema_version' => 1,
            'retrieved_at' => gmdate('c'),
            'response' => $response,
            'metadata' => $metadata,
        ];
        $this->writeRaw('bank_of_canada', $indicatorKey, $this->sanitizeForStorage($rawPayload));

        $observations = [];

        foreach (($response['observations'] ?? []) as $observation) {
            $value = $observation[$seriesId]['v'] ?? null;

            if (!is_string($value) || $value === '') {
                continue;
            }

            $observationValue = Decimal::raw($value)->toString();
            $transformedValue = $this->transformValue($indicatorKey, $config, $observationValue);
            $observations[] = [
                'reference_period' => (string) ($observation['d'] ?? ''),
                'observed_at' => (string) ($observation['d'] ?? ''),
                'release_time' => null,
                'raw_value' => $observationValue,
                'observation_value' => $observationValue,
                'transformed_value' => $transformedValue,
                'source_status' => 'official',
            ];
        }

        if ($observations === []) {
            throw new \RuntimeException('No recent data returned from Bank of Canada for ' . $indicatorKey . '.');
        }

        return [
            'schema_version' => 1,
            'indicator_key' => $indicatorKey,
            'title' => (string) ($config['title'] ?? $indicatorKey),
            'status' => 'ok',
            'classification' => [
                'observation' => 'Observation',
                'transformation' => 'Transformation',
            ],
            'source_key' => 'bank_of_canada',
            'source_series_id' => $seriesId,
            'source_series_title' => (string) ($metadata['seriesDetail'][$seriesId]['label'] ?? ($config['source_series_title'] ?? $seriesId)),
            'source_link' => 'https://www.bankofcanada.ca/valet/observations/' . rawurlencode($seriesId) . '/json?start_date=' . $startDate,
            'frequency' => (string) ($config['frequency'] ?? 'daily'),
            'unit' => (string) ($config['unit'] ?? ''),
            'source_unit' => (string) ($config['source_unit'] ?? ($config['unit'] ?? '')),
            'retrieved_at' => gmdate('c'),
            'transformation_method' => (string) ($config['transformation_method'] ?? 'identity'),
            'transformation_note' => $config['transformation_note'] ?? null,
            'observations' => $observations,
        ];
    }

    private function fetchStatCanSeries(string $indicatorKey, array $config): array
    {
        $vectorId = (string) ($config['source_series_id'] ?? '');
        $seriesInfo = $this->statCanClient->getSeriesInfo($vectorId);
        $latestPeriods = $this->statCanClient->getLatestPeriods($vectorId, 24);
        $rawPayload = [
            'schema_version' => 1,
            'retrieved_at' => gmdate('c'),
            'series_info' => $this->sanitizeForStorage($seriesInfo),
            'latest_periods' => $this->sanitizeForStorage($latestPeriods),
        ];
        $this->writeRaw('statcan', $indicatorKey, $rawPayload);

        $responseCode = (int) ($seriesInfo['object']['responseStatusCode'] ?? 0);

        if ($responseCode !== 0) {
            throw new \RuntimeException('StatCan series unavailable for vector ' . $vectorId . ' (responseStatusCode=' . $responseCode . ').');
        }

        $points = $latestPeriods['object']['vectorDataPoint'] ?? [];
        $observations = [];

        foreach ($points as $point) {
            if (!is_array($point) || !array_key_exists('value', $point) || $point['value'] === null) {
                continue;
            }

            $decimals = (int) ($point['decimals'] ?? 0);
            $observationValue = Decimal::raw(
                $this->normalizeStatCanValue($point['value'], $decimals)
            )->toString();
            $observations[] = [
                'reference_period' => (string) ($point['refPer'] ?? ''),
                'observed_at' => (string) ($point['refPerRaw'] ?? ($point['refPer'] ?? '')),
                'release_time' => isset($point['releaseTime']) ? (string) $point['releaseTime'] : null,
                'raw_value' => $observationValue,
                'observation_value' => $observationValue,
                'transformed_value' => null,
                'source_status' => 'official',
                'decimals' => $decimals,
                'scalar_factor_code' => (int) ($point['scalarFactorCode'] ?? 0),
            ];
        }

        if ($observations === []) {
            throw new \RuntimeException('No recent data returned from StatCan for ' . $indicatorKey . '.');
        }

        $observations = $this->applySeriesTransformations($indicatorKey, $config, $observations);

        return [
            'schema_version' => 1,
            'indicator_key' => $indicatorKey,
            'title' => (string) ($config['title'] ?? $indicatorKey),
            'status' => 'ok',
            'classification' => [
                'observation' => 'Observation',
                'transformation' => 'Transformation',
            ],
            'source_key' => 'statcan_wds',
            'source_series_id' => $vectorId,
            'source_series_title' => (string) ($seriesInfo['object']['SeriesTitleEn'] ?? ($config['source_series_title'] ?? $vectorId)),
            'source_link' => 'https://www150.statcan.gc.ca/t1/wds/rest/getSeriesInfoFromVector',
            'frequency' => (string) ($config['frequency'] ?? 'monthly'),
            'unit' => (string) ($config['unit'] ?? ''),
            'source_unit' => (string) ($config['source_unit'] ?? ($config['unit'] ?? '')),
            'retrieved_at' => gmdate('c'),
            'transformation_method' => (string) ($config['transformation_method'] ?? 'identity'),
            'transformation_note' => $config['transformation_note'] ?? null,
            'observations' => $observations,
        ];
    }

    private function failedSeries(string $indicatorKey, array $config, string $message): array
    {
        return [
            'schema_version' => 1,
            'indicator_key' => $indicatorKey,
            'title' => (string) ($config['title'] ?? $indicatorKey),
            'status' => 'source_failure',
            'classification' => [
                'observation' => 'Observation',
                'transformation' => 'Transformation',
            ],
            'source_key' => (string) ($config['source_key'] ?? ''),
            'source_series_id' => (string) ($config['source_series_id'] ?? ''),
            'source_series_title' => (string) ($config['source_series_title'] ?? ''),
            'source_link' => null,
            'frequency' => (string) ($config['frequency'] ?? 'monthly'),
            'unit' => (string) ($config['unit'] ?? ''),
            'source_unit' => (string) ($config['source_unit'] ?? ($config['unit'] ?? '')),
            'retrieved_at' => gmdate('c'),
            'transformation_method' => (string) ($config['transformation_method'] ?? 'identity'),
            'transformation_note' => $config['transformation_note'] ?? null,
            'missing_reason' => 'source_failure',
            'failure_message' => $message,
            'observations' => [],
        ];
    }

    private function seriesToSnapshotIndicator(array $series): array
    {
        $latest = null;

        foreach (($series['observations'] ?? []) as $observation) {
            if (($observation['transformed_value'] ?? null) !== null) {
                $latest = $observation;
            }
        }

        return [
            'status' => (string) ($series['status'] ?? 'missing'),
            'missing_reason' => $latest === null ? (string) ($series['missing_reason'] ?? 'no_historical_data') : null,
            'value' => $latest['transformed_value'] ?? null,
            'observation_value' => $latest['observation_value'] ?? null,
            'reference_period' => $latest['reference_period'] ?? null,
            'release_time' => $latest['release_time'] ?? null,
            'retrieved_at' => (string) ($series['retrieved_at'] ?? gmdate('c')),
            'source_link' => $series['source_link'] ?? null,
            'source_key' => $series['source_key'] ?? null,
            'source_series_id' => $series['source_series_id'] ?? null,
            'source_series_title' => $series['source_series_title'] ?? null,
            'unit' => $series['unit'] ?? null,
            'source_unit' => $series['source_unit'] ?? null,
            'transformation_method' => $series['transformation_method'] ?? 'identity',
            'transformation_note' => $series['transformation_note'] ?? null,
        ];
    }


    /**
     * @param list<array<string, mixed>> $observations
     * @return list<array<string, mixed>>
     */
    private function applySeriesTransformations(string $indicatorKey, array $config, array $observations): array
    {
        $method = (string) ($config['transformation_method'] ?? 'identity');

        if ($method === 'year_over_year_percent_change') {
            foreach ($observations as $index => $observation) {
                if ($index < 12) {
                    $observations[$index]['transformed_value'] = null;
                    continue;
                }

                $current = Decimal::raw((string) $observation['observation_value']);
                $prior = Decimal::raw((string) $observations[$index - 12]['observation_value']);
                $observations[$index]['transformed_value'] = $current
                    ->divide($prior)
                    ->subtract(Decimal::raw('1.00000000'))
                    ->multiply(Decimal::raw('100.00000000'))
                    ->toString();
            }

            return $observations;
        }

        foreach ($observations as $index => $observation) {
            $observations[$index]['transformed_value'] = $this->transformValue(
                $indicatorKey,
                $config,
                (string) $observation['observation_value']
            );
        }

        return $observations;
    }

    private function transformValue(string $indicatorKey, array $config, string $observationValue): string
    {
        $method = (string) ($config['transformation_method'] ?? 'identity');

        if ($method === 'annualize_monthly_total_units' || $indicatorKey === 'housing_starts') {
            return Decimal::raw($observationValue)
                ->multiply(Decimal::raw('12.00000000'))
                ->toString();
        }

        return Decimal::raw($observationValue)->toString();
    }

    private function writeRaw(string $sourceDirectory, string $indicatorKey, array $payload): void
    {
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'raw' . DIRECTORY_SEPARATOR . $sourceDirectory;
        $store = new JsonStore(new AtomicJsonFile($directory));
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd_His');
        $store->write($indicatorKey . '_' . $timestamp . '.json', $payload);
        $store->write($indicatorKey . '_latest.json', $payload);
    }

    private function sanitizeForStorage(array $value): array
    {
        $sanitized = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizeForStorage($item);
                continue;
            }

            if (is_float($item)) {
                $sanitized[$key] = $this->normalizeFloat($item);
                continue;
            }

            $sanitized[$key] = $item;
        }

        return $sanitized;
    }

    private function normalizeFloat(float $value): string
    {
        $encoded = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');

        return $encoded === '-0' ? '0' : $encoded;
    }

    private function normalizeStatCanValue(mixed $value, int $decimals): string
    {
        if (is_int($value)) {
            return number_format($value, $decimals, '.', '');
        }

        if (is_float($value)) {
            return number_format($value, $decimals, '.', '');
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new \RuntimeException('Unsupported StatCan numeric value type.');
    }
}
