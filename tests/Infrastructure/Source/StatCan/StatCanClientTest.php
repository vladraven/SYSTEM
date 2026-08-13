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
    'changed series uses GET' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"results":[]}'
            )
        );

        $client = createClient($transport);

        $client->getChangedSeriesList();

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'Changed series must use GET.'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList',
            $transport->requests[0]['url'],
            'Changed series URL is incorrect.'
        );
    },

    'series info uses canonical POST endpoint' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":[]}'
            )
        );

        $client = createClient($transport);

        $client->getSeriesInfo('32164132');

        assertSameValue(
            'POST',
            $transport->requests[0]['method'],
            'Series info must use POST.'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getSeriesInfoFromVector',
            $transport->requests[0]['url'],
            'Series info endpoint is incorrect.'
        );

        assertSameValue(
            '[{"vectorId":32164132}]',
            $transport->requests[0]['body'],
            'Series info payload is incorrect.'
        );
    },

    'latest periods uses canonical POST endpoint' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":[]}'
            )
        );

        $client = createClient($transport);

        $client->getLatestPeriods(
            '32164132',
            120
        );

        assertSameValue(
            'POST',
            $transport->requests[0]['method'],
            'Latest periods must use POST.'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods',
            $transport->requests[0]['url'],
            'Latest periods endpoint is incorrect.'
        );

        assertSameValue(
            '[{"vectorId":32164132,"latestN":120}]',
            $transport->requests[0]['body'],
            'Latest periods payload is incorrect.'
        );
    },

    'changed series data uses canonical POST endpoint' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":[]}'
            )
        );

        $client = createClient($transport);

        $client->getChangedSeriesData('32164132');

        assertSameValue(
            'POST',
            $transport->requests[0]['method'],
            'Changed series data must use POST.'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesDataFromVector',
            $transport->requests[0]['url'],
            'Changed series data endpoint is incorrect.'
        );

        assertSameValue(
            '[{"vectorId":32164132}]',
            $transport->requests[0]['body'],
            'Changed series data payload is incorrect.'
        );
    },

    'reference range uses canonical query parameters' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":[]}'
            )
        );

        $client = createClient($transport);

        $client->getReferencePeriodRange(
            [
                '1',
                '2',
            ],
            '2016-01-01',
            '2017-01-01'
        );

        assertSameValue(
            'GET',
            $transport->requests[0]['method'],
            'Reference range must use GET.'
        );

        assertSameValue(
            'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorByReferencePeriodRange?vectorIds=%221%22%2C%222%22&startRefPeriod=2016-01-01&endReferencePeriod=2017-01-01',
            $transport->requests[0]['url'],
            'Reference range URL is incorrect.'
        );
    },

    'full table csv returns official object URL' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":"https://example.test/table.zip"}'
            )
        );

        $client = createClient($transport);

        assertSameValue(
            'https://example.test/table.zip',
            $client->getFullTableCsvUrl('14100287'),
            'Full table CSV URL must be taken from the official response.'
        );
    },

    'full table sdmx returns official object URL' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":"https://example.test/table-sdmx.zip"}'
            )
        );

        $client = createClient($transport);

        assertSameValue(
            'https://example.test/table-sdmx.zip',
            $client->getFullTableSdmxUrl('14100287'),
            'Full table SDMX URL must be taken from the official response.'
        );
    },

    'missing full table csv URL is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                200,
                '{"object":null}'
            )
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getFullTableCsvUrl('14100287');
            },
            'Missing CSV URL must be rejected.'
        );
    },

    'empty vector list is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getReferencePeriodRange(
                    [],
                    '2026-01-01',
                    '2026-02-01'
                );
            },
            'Empty vector list must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'invalid vector id is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeriesInfo('abc');
            },
            'Invalid vector ID must be rejected.'
        );

        assertSameValue(
            0,
            count($transport->requests),
            'Invalid input must not reach HTTP transport.'
        );
    },

    'latestN must be positive' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getLatestPeriods(
                    '123',
                    0
                );
            },
            'latestN must be greater than zero.'
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
                $client->getReferencePeriodRange(
                    ['123'],
                    '2026-1',
                    '2026-02'
                );
            },
            'Invalid reference period must be rejected.'
        );
    },

    'reversed reference range is rejected' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(200, '{}')
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getReferencePeriodRange(
                    ['123'],
                    '2026-12',
                    '2026-01'
                );
            },
            'Reversed reference range must be rejected.'
        );
    },

    'HTTP error is propagated' => static function (): void {
        $transport = new FakeHttpTransport(
            new HttpResponse(
                404,
                '{"error":"not found"}'
            )
        );

        $client = createClient($transport);

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getChangedSeriesList();
            },
            'HTTP errors must propagate.'
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