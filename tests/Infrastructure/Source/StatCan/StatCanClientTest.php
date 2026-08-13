<?php

declare(strict_types=1);

use MacroRisk\Core\Http\HttpClient;
use MacroRisk\Core\Http\HttpResponse;
use MacroRisk\Core\Http\HttpTransport;
use MacroRisk\Infrastructure\Source\StatCan\StatCanClient;
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

final class FakeHttpTransport implements HttpTransport
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

function assertTrueValue(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
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

function createClient(
    FakeHttpTransport $transport
): StatCanClient {
    return new StatCanClient(
        new HttpClient($transport)
    );
}

$tests = [
    'empty product id is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getFullTableDownloadList('');
            },
            'Empty product ID must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'non numeric product id is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getFullTableDownloadList('abc');
            },
            'Non-numeric product ID must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'bulk product id is validated' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getBulkDownloadFileList('abc');
            },
            'Non-numeric bulk product ID must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'empty vector id is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeriesInfo('');
            },
            'Empty vector ID must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'non numeric vector id is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeriesInfo('vector');
            },
            'Non-numeric vector ID must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'empty reference period is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getDataFromVectorByReferencePeriod(
                    '12345',
                    ''
                );
            },
            'Empty reference period must be rejected.'
        );
    },

    'invalid reference period is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getDataFromVectorByReferencePeriod(
                    '12345',
                    '2026-1'
                );
            },
            'Invalid reference period must be rejected.'
        );
    },

    'start period cannot exceed end period' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getDataFromVectorByReferencePeriodRange(
                    '12345',
                    '2026-12',
                    '2026-01'
                );
            },
            'Start period must not exceed end period.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid range must not reach HTTP transport.'
        );
    },

    'changed series endpoint is constructed correctly' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"status":"ok","results":[]}'
            )
        );

        $client = createClient($transport);

        $result = $client->getChangedSeriesList();

        assertSameValue(
            [
                'status' => 'ok',
                'results' => [],
            ],
            $result,
            'StatCan response must be returned unchanged after JSON decoding.'
        );

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'StatCan requests must use GET.'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList',
            $transport->requests[0]['url'],
            'Changed series URL is incorrect.'
        );
    },

    'full table endpoint is constructed correctly' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{"results":[]}')
        );

        $client = createClient($transport);

        $client->getFullTableDownloadList('36100287');

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getFullTableDownloadList/36100287',
            $transport->requests[0]['url'],
            'Full table URL is incorrect.'
        );
    },

    'bulk download endpoint is constructed correctly' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{"results":[]}')
        );

        $client = createClient($transport);

        $client->getBulkDownloadFileList('36100287');

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getBulkDownloadFileList/36100287',
            $transport->requests[0]['url'],
            'Bulk download URL is incorrect.'
        );
    },

    'series info endpoint is constructed correctly' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{"results":[]}')
        );

        $client = createClient($transport);

        $client->getSeriesInfo('41690973');

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getSeriesInfoFromVectorByReferencePeriod/41690973',
            $transport->requests[0]['url'],
            'Series info URL is incorrect.'
        );
    },

    'single period endpoint is constructed correctly' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{"results":[]}')
        );

        $client = createClient($transport);

        $client->getDataFromVectorByReferencePeriod(
            '41690973',
            '2026-01'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorByReferencePeriod/41690973/2026-01',
            $transport->requests[0]['url'],
            'Single-period URL is incorrect.'
        );
    },

    'range endpoint is constructed correctly' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{"results":[]}')
        );

        $client = createClient($transport);

        $client->getDataFromVectorByReferencePeriodRange(
            '41690973',
            '2025-01',
            '2026-01'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorByReferencePeriodRange/41690973/2025-01/2026-01',
            $transport->requests[0]['url'],
            'Range URL is incorrect.'
        );
    },

    'unicode and large integers survive transport decoding' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"title":"Канада","identifier":92233720368547758079223372036854775807}'
            )
        );

        $client = createClient($transport);

        $result = $client->getChangedSeriesList();

        assertSameValue(
            'Канада',
            $result['title'],
            'Unicode response data must be preserved.'
        );

        assertSameValue(
            '92233720368547758079223372036854775807',
            $result['identifier'],
            'Large integer response data must remain exact.'
        );
    },

    'HTTP errors are propagated' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(404, '{"error":"not found"}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getChangedSeriesList();
            },
            'HTTP errors must not be returned as valid StatCan data.'
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
echo 'ALL STATCAN CLIENT TESTS PASSED: ' . $passed . PHP_EOL;