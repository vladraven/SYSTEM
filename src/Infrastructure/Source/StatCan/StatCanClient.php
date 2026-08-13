<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Source\StatCan;

use MacroRisk\Core\Http\HttpClient;
use RuntimeException;

final class StatCanClient
{
    private const BASE_URL =
        'https://www150.statcan.gc.ca/t1/wds/rest';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function getChangedSeriesList(): array
    {
        return $this->get(
            '/getChangedSeriesList'
        );
    }

    public function getSeriesInfo(
        string $vectorId
    ): array {
        $vectorId = trim($vectorId);

        self::assertNumericId(
            $vectorId,
            'vector'
        );

        return $this->http->postJson(
            self::BASE_URL . '/getSeriesInfoFromVector',
            [
                [
                    'vectorId' => self::toIntegerId($vectorId),
                ],
            ]
        );
    }

    public function getLatestPeriods(
        string $vectorId,
        int $latestN
    ): array {
        $vectorId = trim($vectorId);

        self::assertNumericId(
            $vectorId,
            'vector'
        );

        if ($latestN < 1) {
            throw new RuntimeException(
                'StatCan latestN must be greater than zero.'
            );
        }

        return $this->http->postJson(
            self::BASE_URL . '/getDataFromVectorsAndLatestNPeriods',
            [
                [
                    'vectorId' => self::toIntegerId($vectorId),
                    'latestN' => $latestN,
                ],
            ]
        );
    }

    public function getChangedSeriesData(
        string $vectorId
    ): array {
        $vectorId = trim($vectorId);

        self::assertNumericId(
            $vectorId,
            'vector'
        );

        return $this->http->postJson(
            self::BASE_URL . '/getChangedSeriesDataFromVector',
            [
                [
                    'vectorId' => self::toIntegerId($vectorId),
                ],
            ]
        );
    }

    public function getReferencePeriodRange(
        array $vectorIds,
        string $startReferencePeriod,
        string $endReferencePeriod
    ): array {
        $normalizedVectorIds = self::normalizeVectorIds(
            $vectorIds
        );

        self::assertReferencePeriod(
            $startReferencePeriod
        );

        self::assertReferencePeriod(
            $endReferencePeriod
        );

        if ($startReferencePeriod > $endReferencePeriod) {
            throw new RuntimeException(
                'StatCan start reference period cannot be after end reference period.'
            );
        }

        $query = http_build_query(
            [
                'vectorIds' => '"' . implode(
                    '","',
                    $normalizedVectorIds
                ) . '"',
                'startRefPeriod' => $startReferencePeriod,
                'endReferencePeriod' => $endReferencePeriod,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $this->get(
            '/getDataFromVectorByReferencePeriodRange?' . $query
        );
    }

    public function getFullTableCsvUrl(
        string $productId
    ): string {
        $productId = trim($productId);

        self::assertNumericId(
            $productId,
            'product'
        );

        $response = $this->get(
            '/getFullTableDownloadCSV/'
            . $productId
            . '/en'
        );

        if (
            !isset($response['object'])
            || !is_string($response['object'])
            || trim($response['object']) === ''
        ) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: missing StatCan CSV download URL.'
            );
        }

        return $response['object'];
    }

    public function getFullTableSdmxUrl(
        string $productId
    ): string {
        $productId = trim($productId);

        self::assertNumericId(
            $productId,
            'product'
        );

        $response = $this->get(
            '/getFullTableDownloadSDMX/'
            . $productId
        );

        if (
            !isset($response['object'])
            || !is_string($response['object'])
            || trim($response['object']) === ''
        ) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: missing StatCan SDMX download URL.'
            );
        }

        return $response['object'];
    }

    private function get(string $path): array
    {
        return $this->http->getJson(
            self::BASE_URL
            . '/'
            . ltrim($path, '/')
        );
    }

    private static function normalizeVectorIds(
        array $vectorIds
    ): array {
        if ($vectorIds === []) {
            throw new RuntimeException(
                'StatCan vector ID list cannot be empty.'
            );
        }

        $normalized = [];

        foreach ($vectorIds as $vectorId) {
            if (
                !is_string($vectorId)
                && !is_int($vectorId)
            ) {
                throw new RuntimeException(
                    'StatCan vector IDs must be integers or numeric strings.'
                );
            }

            $vectorId = trim((string) $vectorId);

            self::assertNumericId(
                $vectorId,
                'vector'
            );

            $normalized[] = $vectorId;
        }

        return $normalized;
    }

    private static function assertNumericId(
        string $value,
        string $type
    ): void {
        if ($value === '') {
            throw new RuntimeException(
                "StatCan {$type} ID cannot be empty."
            );
        }

        if (!preg_match('/^\d+$/', $value)) {
            throw new RuntimeException(
                "Invalid StatCan {$type} ID: {$value}"
            );
        }
    }

    private static function toIntegerId(
        string $value
    ): int {
        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        if ($integer === false || $integer < 1) {
            throw new RuntimeException(
                "StatCan ID is outside the supported PHP integer range: {$value}"
            );
        }

        return $integer;
    }

    private static function assertReferencePeriod(
        string $referencePeriod
    ): void {
        $referencePeriod = trim($referencePeriod);

        if ($referencePeriod === '') {
            throw new RuntimeException(
                'StatCan reference period cannot be empty.'
            );
        }

        if (!preg_match(
            '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/',
            $referencePeriod
        )) {
            throw new RuntimeException(
                "Invalid StatCan reference period: {$referencePeriod}"
            );
        }
    }
}