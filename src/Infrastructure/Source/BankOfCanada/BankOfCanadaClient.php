<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Source\BankOfCanada;

use MacroRisk\Core\Http\HttpClient;
use RuntimeException;

final class BankOfCanadaClient
{
    private const BASE_URL =
        'https://www.bankofcanada.ca/valet';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function getObservations(
        string $seriesName,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $seriesName = trim($seriesName);

        self::assertSeriesName($seriesName);

        if ($startDate !== null) {
            $startDate = trim($startDate);
            self::assertDate($startDate, 'start date');
        }

        if ($endDate !== null) {
            $endDate = trim($endDate);
            self::assertDate($endDate, 'end date');
        }

        if (
            $startDate !== null
            && $endDate !== null
            && $startDate > $endDate
        ) {
            throw new RuntimeException(
                'Bank of Canada start date cannot exceed end date.'
            );
        }

        $url = self::BASE_URL
            . '/observations/'
            . rawurlencode($seriesName)
            . '/json';

        $query = [];

        if ($startDate !== null) {
            $query['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $query['end_date'] = $endDate;
        }

        if ($query !== []) {
            $url .= '?'
                . http_build_query(
                    $query,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
        }

        $response = $this->http->getJson($url);

        $this->assertObservationResponse(
            $response,
            $seriesName
        );

        return $response;
    }

    public function getSeries(
        string $seriesName
    ): array {
        $seriesName = trim($seriesName);

        self::assertSeriesName($seriesName);

        $response = $this->http->getJson(
            self::BASE_URL
            . '/series/'
            . rawurlencode($seriesName)
            . '/json'
        );

        if (!isset($response['seriesDetail'])) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: missing Bank of Canada seriesDetail.'
            );
        }

        if (!is_array($response['seriesDetail'])) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: Bank of Canada seriesDetail is not an object.'
            );
        }

        if (
            !array_key_exists(
                $seriesName,
                $response['seriesDetail']
            )
        ) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: requested Bank of Canada series is missing from seriesDetail.'
            );
        }

        if (
            !is_array(
                $response['seriesDetail'][$seriesName]
            )
        ) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: Bank of Canada series metadata is not an object.'
            );
        }

        return $response;
    }

    public function getSeriesList(): array
    {
        $response = $this->http->getJson(
            self::BASE_URL
            . '/lists/series/json'
        );

        if (!isset($response['series'])) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: missing Bank of Canada series list.'
            );
        }

        if (!is_array($response['series'])) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: Bank of Canada series list is not an array.'
            );
        }

        return $response;
    }

    private function assertObservationResponse(
        array $response,
        string $seriesName
    ): void {
        if (!array_key_exists('observations', $response)) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: missing Bank of Canada observations.'
            );
        }

        if (!is_array($response['observations'])) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: Bank of Canada observations is not an array.'
            );
        }

        foreach (
            $response['observations']
            as $index => $observation
        ) {
            if (!is_array($observation)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: Bank of Canada observation {$index} is not an object."
                );
            }

            if (!isset($observation['d'])) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: missing observation date at index {$index}."
                );
            }

            if (!is_string($observation['d'])) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: observation date at index {$index} is not a string."
                );
            }

            self::assertDate(
                $observation['d'],
                "observation date at index {$index}"
            );

            if (!array_key_exists(
                $seriesName,
                $observation
            )) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: missing series {$seriesName} at observation {$index}."
                );
            }

            $seriesObservation = $observation[$seriesName];

            if (!is_array($seriesObservation)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: series {$seriesName} at observation {$index} is not an object."
                );
            }

            if (!array_key_exists('v', $seriesObservation)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: missing value for series {$seriesName} at observation {$index}."
                );
            }

            $value = $seriesObservation['v'];

            if ($value === null) {
                continue;
            }

            if (!is_string($value)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: value for series {$seriesName} at observation {$index} must be a decimal string or null."
                );
            }

            if (!self::isDecimalString($value)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: invalid decimal value for series {$seriesName} at observation {$index}."
                );
            }
        }
    }

    private static function assertSeriesName(
        string $seriesName
    ): void {
        if ($seriesName === '') {
            throw new RuntimeException(
                'Bank of Canada series name cannot be empty.'
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_.-]+$/',
                $seriesName
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid Bank of Canada series name: {$seriesName}"
            );
        }
    }

    private static function assertDate(
        string $date,
        string $field
    ): void {
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid Bank of Canada {$field}: {$date}"
            );
        }

        $parts = explode('-', $date);

        if (
            !checkdate(
                (int) $parts[1],
                (int) $parts[2],
                (int) $parts[0]
            )
        ) {
            throw new RuntimeException(
                "Invalid Bank of Canada {$field}: {$date}"
            );
        }
    }

    private static function isDecimalString(
        string $value
    ): bool {
        return preg_match(
            '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/',
            $value
        ) === 1;
    }
}