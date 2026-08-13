<?php

declare(strict_types=1);

use MacroRisk\Core\Http\HttpClient;
use MacroRisk\Core\Http\HttpResponse;
use MacroRisk\Core\Http\HttpTransport;
use MacroRisk\Infrastructure\Source\OpenGovernment\OpenGovernmentClient;
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

final class FakeOpenGovernmentTransport implements HttpTransport
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

function createOpenGovernmentClient(
    FakeOpenGovernmentTransport $transport
): OpenGovernmentClient {
    return new OpenGovernmentClient(
        new HttpClient($transport)
    );
}

$tests = [
    'package search uses canonical GET endpoint' => static function (): void {
        $response = json_encode(
            [
                'success' => true,
                'result' => [
                    'count' => 1,
                    'results' => [
                        [
                            'id' => 'business-insolvencies',
                            'title' => 'Business insolvencies',
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, $response)
        );

        $client = createOpenGovernmentClient($transport);

        $result = $client->packageSearch(
            'business insolvencies'
        );

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'CKAN package search must use GET.'
        );

        assertSameValue(
            'https://open.canada.ca/data/en/api/3/action/package_search?q=business%20insolvencies',
            $transport->requests[0]['url'],
            'CKAN package search endpoint is incorrect.'
        );

        assertSameValue(
            'business-insolvencies',
            $result['result']['results'][0]['id'],
            'CKAN package search result must be preserved.'
        );
    },

    'package show uses canonical GET endpoint' => static function (): void {
        $response = json_encode(
            [
                'success' => true,
                'result' => [
                    'id' => 'business-insolvencies',
                    'title' => 'Business insolvencies',
                    'resources' => [],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, $response)
        );

        $client = createOpenGovernmentClient($transport);

        $result = $client->packageShow(
            'business-insolvencies'
        );

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'CKAN package show must use GET.'
        );

        assertSameValue(
            'https://open.canada.ca/data/en/api/3/action/package_show?id=business-insolvencies',
            $transport->requests[0]['url'],
            'CKAN package show endpoint is incorrect.'
        );

        assertSameValue(
            'Business insolvencies',
            $result['result']['title'],
            'CKAN package metadata must be preserved.'
        );
    },

    'datastore search uses canonical endpoint' => static function (): void {
        $response = json_encode(
            [
                'success' => true,
                'result' => [
                    'records' => [
                        [
                            'date' => '2026-01-01',
                            'value' => '12345.678901234567',
                        ],
                    ],
                    'total' => 1,
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, $response)
        );

        $client = createOpenGovernmentClient($transport);

        $result = $client->dataStoreSearch(
            'resource-123',
            100,
            20,
            'insolvency'
        );

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'CKAN datastore search must use GET.'
        );

        assertSameValue(
            'https://open.canada.ca/data/en/api/3/action/datastore_search?resource_id=resource-123&limit=100&offset=20&q=insolvency',
            $transport->requests[0]['url'],
            'CKAN datastore search endpoint is incorrect.'
        );

        assertSameValue(
            '12345.678901234567',
            $result['result']['records'][0]['value'],
            'CKAN datastore values must be preserved exactly.'
        );
    },

    'package search rejects empty query' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageSearch('');
            },
            'Empty package search query must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid package search query must not reach HTTP.'
        );
    },

    'package id is validated' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageShow('');
            },
            'Empty package id must be rejected.'
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageShow('../package');
            },
            'Invalid package id must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid package id must not reach HTTP.'
        );
    },

    'resource id is validated' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch('');
            },
            'Empty resource id must be rejected.'
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch(
                    '../resource'
                );
            },
            'Invalid resource id must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid resource id must not reach HTTP.'
        );
    },

    'datastore limit must be positive' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch(
                    'resource-123',
                    0
                );
            },
            'Zero datastore limit must be rejected.'
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch(
                    'resource-123',
                    -1
                );
            },
            'Negative datastore limit must be rejected.'
        );
    },

    'datastore limit cannot exceed maximum' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch(
                    'resource-123',
                    10001
                );
            },
            'Datastore limit above 10000 must be rejected.'
        );
    },

    'datastore offset cannot be negative' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch(
                    'resource-123',
                    100,
                    -1
                );
            },
            'Negative datastore offset must be rejected.'
        );
    },

    'empty datastore query is rejected' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, '{}')
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->dataStoreSearch(
                    'resource-123',
                    100,
                    0,
                    '   '
                );
            },
            'Empty datastore query must be rejected.'
        );
    },

    'missing success field is rejected' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(
                200,
                '{"result":{}}'
            )
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageSearch('test');
            },
            'Missing CKAN success field must be rejected.'
        );
    },

    'non boolean success field is rejected' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(
                200,
                '{"success":"true","result":{}}'
            )
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageSearch('test');
            },
            'Non-boolean CKAN success field must be rejected.'
        );
    },

    'unsuccessful CKAN response is rejected' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(
                200,
                '{"success":false,"result":{"error":"invalid"}}'
            )
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageSearch('test');
            },
            'Unsuccessful CKAN response must be rejected.'
        );
    },

    'missing result field is rejected' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(
                200,
                '{"success":true}'
            )
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageSearch('test');
            },
            'Missing CKAN result must be rejected.'
        );
    },

    'non object result is rejected' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(
                200,
                '{"success":true,"result":"invalid"}'
            )
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageSearch('test');
            },
            'Non-object CKAN result must be rejected.'
        );
    },

    'HTTP errors are propagated' => static function (): void {
        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(
                404,
                '{"message":"Not found"}'
            )
        );

        $client = createOpenGovernmentClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->packageShow(
                    'missing-dataset'
                );
            },
            'HTTP errors must propagate.'
        );
    },

    'realistic CKAN package response is accepted' => static function (): void {
        $response = json_encode(
            [
                'success' => true,
                'result' => [
                    'id' => 'business-insolvencies',
                    'title' => 'Business insolvencies',
                    'notes' => 'Official Government of Canada dataset.',
                    'resources' => [
                        [
                            'id' => 'resource-123',
                            'format' => 'CSV',
                            'datastore_active' => true,
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $transport = new FakeOpenGovernmentTransport(
            new HttpResponse(200, $response)
        );

        $client = createOpenGovernmentClient($transport);

        $result = $client->packageShow(
            'business-insolvencies'
        );

        assertSameValue(
            'business-insolvencies',
            $result['result']['id'],
            'CKAN dataset identifier must be preserved.'
        );

        assertSameValue(
            'resource-123',
            $result['result']['resources'][0]['id'],
            'CKAN resource identifier must be preserved.'
        );

        assertSameValue(
            true,
            $result['result']['resources'][0]['datastore_active'],
            'CKAN datastore availability must be preserved.'
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
echo 'ALL OPEN GOVERNMENT CLIENT TESTS PASSED: ' . $passed . PHP_EOL;