<?php

declare(strict_types=1);

use MacroRisk\Core\Http\HttpClient;
use MacroRisk\Core\Http\HttpResponse;
use MacroRisk\Core\Http\HttpTransport;
use MacroRisk\Infrastructure\Source\StatCan\StatCanClient;
use MacroRisk\Infrastructure\Source\StatCan\StatCanSeriesReader;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'MacroRisk\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    $file = dirname(__DIR__, 4)
        . '/src/'
        . str_replace('\\', '/', $relative)
        . '.php';

    if (is_file($file)) {
        require $file;
    }
});

final class FakeStatCanReaderTransport implements HttpTransport
{
    public array $requests = [];

    public function __construct(
        private readonly HttpResponse $response
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): HttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return $this->response;
    }
}

function assertSameValue(
    mixed $expected,
    mixed $actual,
    string $message
): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . PHP_EOL
            . 'Expected: ' . var_export($expected, true)
            . PHP_EOL
            . 'Actual: ' . var_export($actual, true)
        );
    }
}

function assertCountValue(
    int $expected,
    array $actual,
    string $message
): void {
    if (count($actual) !== $expected) {
        throw new RuntimeException(
            $message
            . PHP_EOL
            . 'Expected count: ' . $expected
            . PHP_EOL
            . 'Actual count: ' . count($actual)
        );
    }
}

function assertThrows(
    string $exceptionClass,
    callable $callback,
    string $message
): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            $message
            . PHP_EOL
            . 'Expected exception: ' . $exceptionClass
            . PHP_EOL
            . 'Actual exception: ' . $exception::class,
            0,
            $exception
        );
    }

    throw new RuntimeException(
        $message
        . PHP_EOL
        . 'Expected exception: ' . $exceptionClass
        . PHP_EOL
        . 'Actual: no exception'
    );
}

function createReader(
    FakeStatCanReaderTransport $transport
): StatCanSeriesReader {
    return new StatCanSeriesReader(
        new StatCanClient(
            new HttpClient($transport)
        )
    );
}

function successResponse(
    array $datapoints,
    int $productId = 35100003,
    int $vectorId = 32164132,
    string $coordinate = '1.12.0.0.0.0.0.0.0.0'
): string {
    return json_encode(
        [
            [
                'status' => 'SUCCESS',
                'object' => [
                    'responseStatusCode' => 0,
                    'productId' => $productId,
                    'coordinate' => $coordinate,
                    'vectorId' => $vectorId,
                    'vectorDataPoint' => $datapoints,
                ],
            ],
        ],
        JSON_THROW_ON_ERROR
    );
}

$tests = [
    'latest periods are parsed into deterministic records' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                successResponse([
                    [
                        'refPer' => '2017-07-01',
                        'refPer2' => '',
                        'refPerRaw' => '2017-01-01',
                        'refPerRaw2' => '',
                        'value' => '18381',
                        'decimals' => 0,
                        'scalarFactorCode' => 0,
                        'symbolCode' => 0,
                        'statusCode' => 0,
                        'securityLevelCode' => 0,
                        'releaseTime' => '2017-12-07T08:30',
                        'frequencyCode' => 6,
                    ],
                ])
            )
        );

        $reader = createReader($transport);

        $records = $reader->readLatestPeriods(
            '32164132',
            1
        );

        assertCountValue(
            1,
            $records,
            'One source datapoint must produce one parsed record.'
        );

        assertSameValue(
            [
                'source' => 'statcan',
                'product_id' => 35100003,
                'vector_id' => 32164132,
                'coordinate' => '1.12.0.0.0.0.0.0.0.0',
                'ref_per' => '2017-07-01',
                'ref_per_2' => '',
                'ref_per_raw' => '2017-01-01',
                'ref_per_raw_2' => '',
                'value' => '18381',
                'decimals' => 0,
                'scalar_factor_code' => 0,
                'symbol_code' => 0,
                'status_code' => 0,
                'security_level_code' => 0,
                'release_time' => '2017-12-07T08:30',
                'frequency_code' => 6,
            ],
            $records[0],
            'Parsed StatCan record must preserve source fields.'
        );
    },

    'integer value is converted to decimal string without precision loss' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                successResponse([
                    [
                        'refPer' => '2026-01-01',
                        'refPerRaw' => '2026-01-01',
                        'value' => 36474968,
                        'decimals' => 0,
                        'scalarFactorCode' => 0,
                        'symbolCode' => 0,
                        'statusCode' => 0,
                        'securityLevelCode' => 0,
                        'releaseTime' => '2026-02-01T08:30',
                        'frequencyCode' => 9,
                    ],
                ])
            )
        );

        $reader = createReader($transport);

        $records = $reader->readChangedSeriesData(
            '32164132'
        );

        assertSameValue(
            '36474968',
            $records[0]['value'],
            'Integer source values must become decimal strings.'
        );
    },

    'string decimal value is preserved exactly' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                successResponse([
                    [
                        'refPer' => '2026-01-01',
                        'refPerRaw' => '2026-01-01',
                        'value' => '12345.678901234567',
                        'decimals' => 6,
                        'scalarFactorCode' => 0,
                        'symbolCode' => 0,
                        'statusCode' => 0,
                        'releaseTime' => '2026-02-01T08:30',
                        'frequencyCode' => 9,
                    ],
                ])
            )
        );

        $reader = createReader($transport);

        $records = $reader->readChangedSeriesData(
            '32164132'
        );

        assertSameValue(
            '12345.678901234567',
            $records[0]['value'],
            'Decimal strings must be preserved exactly.'
        );
    },

    'floating point value is normalized' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                '[
                    {
                        "status": "SUCCESS",
                        "object": {
                            "responseStatusCode": 0,
                            "productId": 35100003,
                            "coordinate": "1.0",
                            "vectorId": 32164132,
                            "vectorDataPoint": [
                                {
                                    "refPer": "2026-01-01",
                                    "refPerRaw": "2026-01-01",
                                    "value": 528800.0,
                                    "decimals": 1,
                                    "scalarFactorCode": 0,
                                    "symbolCode": 0,
                                    "statusCode": 0,
                                    "securityLevelCode": 0,
                                    "releaseTime": "2026-02-01T08:30",
                                    "frequencyCode": 9
                                }
                            ]
                        }
                    }
                ]'
            )
        );

        $reader = createReader($transport);

        $records = $reader->readChangedSeriesData(
            '32164132'
        );

        assertSameValue(
            '528800.0',
            $records[0]['value'],
            'Floating point source values must be normalized to decimal strings.'
        );
    },

    'invalid decimal string is rejected' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                successResponse([
                    [
                        'refPer' => '2026-01-01',
                        'refPerRaw' => '2026-01-01',
                        'value' => '1.2.3',
                        'decimals' => 2,
                        'scalarFactorCode' => 0,
                        'symbolCode' => 0,
                        'statusCode' => 0,
                        'releaseTime' => '2026-02-01T08:30',
                        'frequencyCode' => 9,
                    ],
                ])
            )
        );

        $reader = createReader($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($reader): void {
                $reader->readChangedSeriesData(
                    '32164132'
                );
            },
            'Invalid decimal strings must be rejected.'
        );
    },

    'missing datapoint field is rejected' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                successResponse([
                    [
                        'refPer' => '2026-01-01',
                        'refPerRaw' => '2026-01-01',
                        'value' => '10',
                        'decimals' => 0,
                        'scalarFactorCode' => 0,
                        'symbolCode' => 0,
                        'statusCode' => 0,
                        'releaseTime' => '2026-02-01T08:30',
                    ],
                ])
            )
        );

        $reader = createReader($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($reader): void {
                $reader->readChangedSeriesData(
                    '32164132'
                );
            },
            'Missing frequencyCode must be rejected.'
        );
    },

    'nonzero response status is rejected' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                json_encode(
                    [
                        [
                            'status' => 'SUCCESS',
                            'object' => [
                                'responseStatusCode' => 1,
                                'productId' => 35100003,
                                'coordinate' => '1.0',
                                'vectorId' => 32164132,
                                'vectorDataPoint' => [],
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR
                )
            )
        );

        $reader = createReader($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($reader): void {
                $reader->readChangedSeriesData(
                    '32164132'
                );
            },
            'Nonzero responseStatusCode must be rejected.'
        );
    },

    'failed envelope status is rejected' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                json_encode(
                    [
                        [
                            'status' => 'FAILED',
                            'object' => null,
                        ],
                    ],
                    JSON_THROW_ON_ERROR
                )
            )
        );

        $reader = createReader($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($reader): void {
                $reader->readChangedSeriesData(
                    '32164132'
                );
            },
            'Failed StatCan envelopes must be rejected.'
        );
    },

    'empty datapoint collection is rejected' => static function (): void {
        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                successResponse([])
            )
        );

        $reader = createReader($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($reader): void {
                $reader->readChangedSeriesData(
                    '32164132'
                );
            },
            'Empty datapoint responses must be rejected.'
        );
    },

    'multiple envelopes and datapoints are flattened deterministically' => static function (): void {
        $response = [
            [
                'status' => 'SUCCESS',
                'object' => [
                    'responseStatusCode' => 0,
                    'productId' => 35100003,
                    'coordinate' => '1.0',
                    'vectorId' => 1,
                    'vectorDataPoint' => [
                        [
                            'refPer' => '2026-01-01',
                            'refPerRaw' => '2026-01-01',
                            'value' => '1',
                            'decimals' => 0,
                            'scalarFactorCode' => 0,
                            'symbolCode' => 0,
                            'statusCode' => 0,
                            'securityLevelCode' => 0,
                            'releaseTime' => '2026-02-01T08:30',
                            'frequencyCode' => 9,
                        ],
                        [
                            'refPer' => '2026-04-01',
                            'refPerRaw' => '2026-04-01',
                            'value' => '2',
                            'decimals' => 0,
                            'scalarFactorCode' => 0,
                            'symbolCode' => 0,
                            'statusCode' => 0,
                            'securityLevelCode' => 0,
                            'releaseTime' => '2026-05-01T08:30',
                            'frequencyCode' => 9,
                        ],
                    ],
                ],
            ],
            [
                'status' => 'SUCCESS',
                'object' => [
                    'responseStatusCode' => 0,
                    'productId' => 35100003,
                    'coordinate' => '2.0',
                    'vectorId' => 2,
                    'vectorDataPoint' => [
                        [
                            'refPer' => '2026-01-01',
                            'refPerRaw' => '2026-01-01',
                            'value' => '3',
                            'decimals' => 0,
                            'scalarFactorCode' => 0,
                            'symbolCode' => 0,
                            'statusCode' => 0,
                            'securityLevelCode' => 0,
                            'releaseTime' => '2026-02-01T08:30',
                            'frequencyCode' => 9,
                        ],
                    ],
                ],
            ],
        ];

        $transport = new FakeStatCanReaderTransport(
            new HttpResponse(
                200,
                json_encode(
                    $response,
                    JSON_THROW_ON_ERROR
                )
            )
        );

        $reader = createReader($transport);

        $records = $reader->readReferencePeriodRange(
            [
                '1',
                '2',
            ],
            '2026-01-01',
            '2026-04-01'
        );

        assertCountValue(
            3,
            $records,
            'All datapoints from all envelopes must be flattened.'
        );

        assertSameValue(
            1,
            $records[0]['vector_id'],
            'First record must retain first vector identity.'
        );

        assertSameValue(
            '2',
            $records[1]['value'],
            'Second record must retain source value.'
        );

        assertSameValue(
            2,
            $records[2]['vector_id'],
            'Third record must retain second vector identity.'
        );
    },
];

$passed = 0;

foreach ($tests as $name => $test) {
    $test();
    $passed++;

    echo '[OK] ' . $name . PHP_EOL;
}

echo PHP_EOL;
echo 'ALL STATCAN SERIES READER TESTS PASSED: ' . $passed . PHP_EOL;