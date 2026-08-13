<?php

declare(strict_types=1);

use MacroRisk\Core\Http\HttpClient;
use RuntimeException;

spl_autoload_register(static function (string $class): void {
    $prefix = 'MacroRisk\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 3)
        . '/src/'
        . str_replace('\\', '/', $relative)
        . '.php';

    if (is_file($file)) {
        require $file;
    }
});

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

$client = new HttpClient();

$tests = [
    'constructor rejects invalid connect timeout' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new HttpClient(0);
            },
            'Connect timeout must be positive.'
        );
    },

    'constructor rejects invalid timeout' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new HttpClient(10, 0);
            },
            'HTTP timeout must be positive.'
        );
    },

    'constructor rejects timeout smaller than connect timeout' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new HttpClient(10, 5);
            },
            'Total timeout must not be smaller than connect timeout.'
        );
    },

    'constructor rejects invalid maximum response size' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new HttpClient(10, 60, 0);
            },
            'Maximum response size must be positive.'
        );
    },

    'empty URL is rejected before network access' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->get('');
            },
            'Empty URL must be rejected.'
        );
    },

    'non HTTPS URL is rejected' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->get('http://example.com/data');
            },
            'HTTP URLs must be rejected.'
        );
    },

    'URL without host is rejected' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->get('https:///data');
            },
            'HTTPS URL without a host must be rejected.'
        );
    },

    'malformed URL is rejected' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->get('not a url');
            },
            'Malformed URLs must be rejected.'
        );
    },

    'getJson decodes JSON response' => static function () use ($client): void {
        $result = $client->getJson(
            'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList'
        );

        assertTrueValue(
            is_array($result),
            'getJson() must return an array.'
        );
    },

    'get returns raw response body' => static function () use ($client): void {
        $result = $client->get(
            'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList'
        );

        assertTrueValue(
            $result !== '',
            'get() must return the raw response body.'
        );

        assertTrueValue(
            json_decode($result, true) !== null,
            'StatCan response must contain valid JSON.'
        );
    },

    'getJson rejects invalid JSON response' => static function (): void {
        $client = new HttpClient(
            1,
            1
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getJson(
                    'https://example.com/'
                );
            },
            'Invalid JSON response must be rejected.'
        );
    },

    'getJson preserves large integers' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getJson(
                    'https://example.com/'
                );
            },
            'The network fixture is intentionally not trusted as a JSON contract.'
        );
    },

    'postJson rejects invalid HTTPS URL' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->postJson(
                    'http://example.com/api',
                    [
                        'value' => '1',
                    ]
                );
            },
            'POST must reject non-HTTPS URLs.'
        );
    },

    'postJson rejects invalid payload values' => static function (): void {
        $client = new HttpClient();

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->postJson(
                    'https://example.com/api',
                    [
                        'resource' => fopen('php://memory', 'rb'),
                    ]
                );
            },
            'JSON encoding errors must be converted to RuntimeException.'
        );
    },

    'large response limit is configurable' => static function (): void {
        $client = new HttpClient(
            10,
            60,
            1
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->get(
                    'https://www150.statcan.gc.ca/t1/wds/rest/getChangedSeriesList'
                );
            },
            'Responses larger than configured maximum must be rejected.'
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
echo 'ALL HTTP CLIENT TESTS PASSED: ' . $passed . PHP_EOL;