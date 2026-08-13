<?php

declare(strict_types=1);

use MacroRisk\Core\Http\HttpClient;
use MacroRisk\Core\Http\HttpResponse;
use MacroRisk\Core\Http\HttpTransport;
use MacroRisk\Infrastructure\Source\BankOfCanada\BankOfCanadaClient;
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

final class FakeBankOfCanadaTransport implements HttpTransport
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

function createBankOfCanadaClient(
    FakeBankOfCanadaTransport $transport
): BankOfCanadaClient {
    return new BankOfCanadaClient(
        new HttpClient($transport)
    );
}

$tests = [
    'observations use canonical GET endpoint' => static function (): void {
        $response = json_encode(
            [
                'observations' => [
                    [
                        'd' => '2026-07-20',
                        'V39079' => [
                            'v' => '2.25',
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $client->getObservations('V39079');

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'Bank of Canada observations must use GET.'
        );

        assertSameValue(
            'https://www.bankofcanada.ca/valet/observations/V39079/json',
            $transport->requests[0]['url'],
            'Bank of Canada observations endpoint is incorrect.'
        );
    },

    'date range is encoded correctly' => static function (): void {
        $response = json_encode(
            [
                'observations' => [],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $client->getObservations(
            'V39079',
            '2020-01-01',
            '2026-08-13'
        );

        assertSameValue(
            'https://www.bankofcanada.ca/valet/observations/V39079/json?start_date=2020-01-01&end_date=2026-08-13',
            $transport->requests[0]['url'],
            'Bank of Canada observation date range is incorrect.'
        );
    },

    'series metadata uses canonical endpoint' => static function (): void {
        $response = json_encode(
            [
                'seriesDetail' => [
                    'V39079' => [
                        'label' => 'Target for the overnight rate',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $result = $client->getSeries('V39079');

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'Bank of Canada series metadata must use GET.'
        );

        assertSameValue(
            'https://www.bankofcanada.ca/valet/series/V39079/json',
            $transport->requests[0]['url'],
            'Bank of Canada series endpoint is incorrect.'
        );

        assertSameValue(
            'Target for the overnight rate',
            $result['seriesDetail']['V39079']['label'],
            'Series metadata must be preserved.'
        );
    },

    'series list uses canonical endpoint' => static function (): void {
        $response = json_encode(
            [
                'series' => [
                    [
                        'seriesName' => 'V39079',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $result = $client->getSeriesList();

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'Bank of Canada series list must use GET.'
        );

        assertSameValue(
            'https://www.bankofcanada.ca/valet/lists/series/json',
            $transport->requests[0]['url'],
            'Bank of Canada series list endpoint is incorrect.'
        );

        assertSameValue(
            'V39079',
            $result['series'][0]['seriesName'],
            'Series list must be preserved.'
        );
    },

    'decimal observation values are preserved exactly' => static function (): void {
        $response = json_encode(
            [
                'observations' => [
                    [
                        'd' => '2026-01-01',
                        'V39079' => [
                            'v' => '12345.678901234567',
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $result = $client->getObservations('V39079');

        assertSameValue(
            '12345.678901234567',
            $result['observations'][0]['V39079']['v'],
            'Decimal observation values must remain exact strings.'
        );
    },

    'null observation value is accepted as missing observation' => static function (): void {
        $response = json_encode(
            [
                'observations' => [
                    [
                        'd' => '2026-01-01',
                        'V39079' => [
                            'v' => null,
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $result = $client->getObservations('V39079');

        assertSameValue(
            null,
            $result['observations'][0]['V39079']['v'],
            'Missing source values must remain null.'
        );
    },

    'empty series name is rejected' => static function (): void {
        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, '{}')
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations('');
            },
            'Empty series name must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'invalid series name is rejected' => static function (): void {
        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, '{}')
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations(
                    'V39079/../../etc'
                );
            },
            'Invalid series name must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'invalid start date is rejected' => static function (): void {
        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, '{}')
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations(
                    'V39079',
                    '2026-02-30'
                );
            },
            'Invalid start date must be rejected.'
        );
    },

    'invalid end date is rejected' => static function (): void {
        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, '{}')
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations(
                    'V39079',
                    null,
                    '2026-13-01'
                );
            },
            'Invalid end date must be rejected.'
        );
    },

    'start date cannot exceed end date' => static function (): void {
        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, '{}')
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations(
                    'V39079',
                    '2026-08-13',
                    '2026-08-01'
                );
            },
            'Reversed date range must be rejected.'
        );
    },

    'missing observations array is rejected' => static function (): void {
        $response = json_encode(
            [
                'seriesDetail' => [],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations('V39079');
            },
            'Missing observations array must be rejected.'
        );
    },

    'missing series value is rejected' => static function (): void {
        $response = json_encode(
            [
                'observations' => [
                    [
                        'd' => '2026-01-01',
                        'V39079' => [],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations('V39079');
            },
            'Missing observation value must be rejected.'
        );
    },

    'invalid decimal value is rejected' => static function (): void {
        $response = json_encode(
            [
                'observations' => [
                    [
                        'd' => '2026-01-01',
                        'V39079' => [
                            'v' => '1.2.3',
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations('V39079');
            },
            'Invalid decimal observation must be rejected.'
        );
    },

    'missing series metadata is rejected' => static function (): void {
        $response = json_encode(
            [
                'seriesDetail' => [],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeries('V39079');
            },
            'Missing requested series metadata must be rejected.'
        );
    },

    'missing series list is rejected' => static function (): void {
        $response = json_encode(
            [
                'foo' => [],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeriesList();
            },
            'Missing series list must be rejected.'
        );
    },

    'HTTP errors are propagated' => static function (): void {
        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(
                404,
                '{"message":"Not found"}'
            )
        );

        $client = createBankOfCanadaClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getObservations('V39079');
            },
            'HTTP errors must propagate.'
        );
    },

    'realistic Bank of Canada response is accepted' => static function (): void {
        $response = json_encode(
            [
                'termsOfUse' => 'https://www.bankofcanada.ca/terms/',
                'seriesDetail' => [
                    'V39079' => [
                        'label' => 'Target for the overnight rate',
                        'description' => 'Target for the overnight rate',
                    ],
                ],
                'observations' => [
                    [
                        'd' => '2026-07-20',
                        'V39079' => [
                            'v' => '2.25',
                        ],
                    ],
                    [
                        'd' => '2026-07-21',
                        'V39079' => [
                            'v' => '2.25',
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeBankOfCanadaTransport(
            new HttpResponse(200, $response)
        );

        $client = createBankOfCanadaClient($transport);

        $result = $client->getObservations(
            'V39079',
            '2026-07-20',
            '2026-07-21'
        );

        assertCountValue(
            2,
            $result['observations'],
            'Realistic Bank of Canada observations must be preserved.'
        );

        assertSameValue(
            '2026-07-20',
            $result['observations'][0]['d'],
            'Observation date must be preserved.'
        );

        assertSameValue(
            '2.25',
            $result['observations'][0]['V39079']['v'],
            'Observation value must be preserved as a decimal string.'
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
echo 'ALL BANK OF CANADA CLIENT TESTS PASSED: ' . $passed . PHP_EOL;