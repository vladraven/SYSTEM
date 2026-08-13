<?php

declare(strict_types=1);

use MacroRisk\Core\Http\HttpClient;
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

function assertTrueValue(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
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

$client = new StatCanClient(
    new HttpClient()
);

$tests = [
    'empty product id is rejected' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getFullTableDownloadList('');
            },
            'Empty product ID must be rejected.'
        );
    },

    'non numeric product id is rejected' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getFullTableDownloadList('abc');
            },
            'Non-numeric product ID must be rejected.'
        );
    },

    'empty bulk product id is rejected' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getBulkDownloadFileList('');
            },
            'Empty product ID must be rejected.'
        );
    },

    'empty vector id is rejected' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeriesInfo('');
            },
            'Empty vector ID must be rejected.'
        );
    },

    'non numeric vector id is rejected' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getSeriesInfo('vector');
            },
            'Non-numeric vector ID must be rejected.'
        );
    },

    'empty reference period is rejected' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

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
        $client = new StatCanClient(
            new HttpClient()
        );

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

    'start reference period cannot exceed end period' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getDataFromVectorByReferencePeriodRange(
                    '12345',
                    '2026-12',
                    '2026-01'
                );
            },
            'Start reference period must not exceed end reference period.'
        );
    },

    'year reference period is accepted' => static function (): void {
        $client = new StatCanClient(
            new HttpClient(
                1,
                1
            )
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getDataFromVectorByReferencePeriod(
                    '12345',
                    '2026'
                );
            },
            'Valid input must pass local validation before transport.'
        );
    },

    'monthly reference period is accepted' => static function (): void {
        $client = new StatCanClient(
            new HttpClient(
                1,
                1
            )
        );

        assertThrows(
            RuntimeException::class,
            static function () use ($client): void {
                $client->getDataFromVectorByReferencePeriod(
                    '12345',
                    '2026-01'
                );
            },
            'Valid monthly period must pass local validation before transport.'
        );
    },

    'real StatCan WDS endpoint returns an array' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        $result = $client->getChangedSeriesList();

        assertTrueValue(
            is_array($result),
            'StatCan WDS response must be an array.'
        );
    },

    'real StatCan vector endpoint returns an array' => static function (): void {
        $client = new StatCanClient(
            new HttpClient()
        );

        $result = $client->getDataFromVectorByReferencePeriod(
            '41690973',
            '2025-01'
        );

        assertTrueValue(
            is_array($result),
            'StatCan vector response must be an array.'
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