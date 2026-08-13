<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Source\StatCan;

use RuntimeException;

final class StatCanSeriesReader
{
    public function __construct(
        private readonly StatCanClient $client
    ) {
    }

    public function readLatestPeriods(
        string $vectorId,
        int $latestN
    ): array {
        return $this->parseResponse(
            $this->client->getLatestPeriods(
                $vectorId,
                $latestN
            )
        );
    }

    public function readChangedSeriesData(
        string $vectorId
    ): array {
        return $this->parseResponse(
            $this->client->getChangedSeriesData(
                $vectorId
            )
        );
    }

    public function readReferencePeriodRange(
        array $vectorIds,
        string $startReferencePeriod,
        string $endReferencePeriod
    ): array {
        return $this->parseResponse(
            $this->client->getReferencePeriodRange(
                $vectorIds,
                $startReferencePeriod,
                $endReferencePeriod
            )
        );
    }

    private function parseResponse(
        array $response
    ): array {
        if ($response === []) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: empty StatCan response.'
            );
        }

        $records = [];

        foreach ($response as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: StatCan response entry {$index} is not an object."
                );
            }

            $records = array_merge(
                $records,
                $this->parseEnvelope(
                    $entry,
                    (string) $index
                )
            );
        }

        if ($records === []) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: StatCan response contains no datapoints.'
            );
        }

        return $records;
    }

    private function parseEnvelope(
        array $entry,
        string $path
    ): array {
        $status = $entry['status'] ?? null;

        if (!is_string($status)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing StatCan status at {$path}."
            );
        }

        if ($status !== 'SUCCESS') {
            throw new RuntimeException(
                "SOURCE_RESPONSE_ERROR: StatCan returned status {$status} at {$path}."
            );
        }

        $object = $entry['object'] ?? null;

        if (!is_array($object)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing StatCan object at {$path}."
            );
        }

        $this->assertResponseStatus(
            $object,
            $path
        );

        $productId = $this->requireInteger(
            $object,
            'productId',
            $path
        );

        $vectorId = $this->requireInteger(
            $object,
            'vectorId',
            $path
        );

        $coordinate = $this->requireString(
            $object,
            'coordinate',
            $path
        );

        $datapoints = $object['vectorDataPoint'] ?? null;

        if (!is_array($datapoints)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing vectorDataPoint at {$path}."
            );
        }

        $records = [];

        foreach ($datapoints as $datapointIndex => $datapoint) {
            if (!is_array($datapoint)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: datapoint {$path}.vectorDataPoint[{$datapointIndex}] is not an object."
                );
            }

            $records[] = $this->parseDatapoint(
                $datapoint,
                $path . '.vectorDataPoint[' . $datapointIndex . ']',
                $productId,
                $vectorId,
                $coordinate
            );
        }

        return $records;
    }

    private function parseDatapoint(
        array $datapoint,
        string $path,
        int $productId,
        int $vectorId,
        string $coordinate
    ): array {
        return [
            'source' => 'statcan',
            'product_id' => $productId,
            'vector_id' => $vectorId,
            'coordinate' => $coordinate,
            'ref_per' => $this->requireString(
                $datapoint,
                'refPer',
                $path
            ),
            'ref_per_2' => $this->requireOptionalString(
                $datapoint,
                'refPer2',
                $path
            ),
            'ref_per_raw' => $this->requireString(
                $datapoint,
                'refPerRaw',
                $path
            ),
            'ref_per_raw_2' => $this->requireOptionalString(
                $datapoint,
                'refPerRaw2',
                $path
            ),
            'value' => $this->parseDecimalValue(
                $datapoint,
                'value',
                $path
            ),
            'decimals' => $this->requireInteger(
                $datapoint,
                'decimals',
                $path
            ),
            'scalar_factor_code' => $this->requireInteger(
                $datapoint,
                'scalarFactorCode',
                $path
            ),
            'symbol_code' => $this->requireInteger(
                $datapoint,
                'symbolCode',
                $path
            ),
            'status_code' => $this->requireInteger(
                $datapoint,
                'statusCode',
                $path
            ),
            'security_level_code' => $this->requireInteger(
                $datapoint,
                'securityLevelCode',
                $path
            ),
            'release_time' => $this->requireString(
                $datapoint,
                'releaseTime',
                $path
            ),
            'frequency_code' => $this->requireInteger(
                $datapoint,
                'frequencyCode',
                $path
            ),
        ];
    }

    private function parseDecimalValue(
        array $data,
        string $key,
        string $path
    ): string {
        if (!array_key_exists($key, $data)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing {$key} at {$path}."
            );
        }

        $value = $data[$key];

        if (is_string($value)) {
            if (!self::isDecimalString($value)) {
                throw new RuntimeException(
                    "SOURCE_SCHEMA_MISMATCH: invalid decimal value at {$path}.{$key}."
                );
            }

            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        throw new RuntimeException(
            "SOURCE_SCHEMA_MISMATCH: decimal value must be string or integer at {$path}.{$key}."
        );
    }

    private function assertResponseStatus(
        array $object,
        string $path
    ): void {
        if (!array_key_exists('responseStatusCode', $object)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing responseStatusCode at {$path}."
            );
        }

        if (
            !is_int($object['responseStatusCode'])
            && !is_string($object['responseStatusCode'])
        ) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: invalid responseStatusCode at {$path}."
            );
        }

        if ((string) $object['responseStatusCode'] !== '0') {
            throw new RuntimeException(
                'SOURCE_RESPONSE_ERROR: StatCan responseStatusCode is not zero.'
            );
        }
    }

    private function requireInteger(
        array $data,
        string $key,
        string $path
    ): int {
        if (!array_key_exists($key, $data)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing {$key} at {$path}."
            );
        }

        $value = $data[$key];

        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && preg_match('/^-?\d+$/', $value) === 1
        ) {
            $integer = filter_var(
                $value,
                FILTER_VALIDATE_INT
            );

            if ($integer !== false) {
                return $integer;
            }
        }

        throw new RuntimeException(
            "SOURCE_SCHEMA_MISMATCH: {$key} must be an integer at {$path}."
        );
    }

    private function requireString(
        array $data,
        string $key,
        string $path
    ): string {
        if (!array_key_exists($key, $data)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing {$key} at {$path}."
            );
        }

        if (!is_string($data[$key])) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: {$key} must be a string at {$path}."
            );
        }

        return $data[$key];
    }

    private function requireOptionalString(
        array $data,
        string $key,
        string $path
    ): string {
        if (!array_key_exists($key, $data)) {
            return '';
        }

        if (!is_string($data[$key])) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: {$key} must be a string at {$path}."
            );
        }

        return $data[$key];
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