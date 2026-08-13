<?php

declare(strict_types=1);

use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;
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

function assertFalseValue(
    bool $condition,
    string $message
): void {
    if ($condition) {
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

function createTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'macrorisk-json-'
        . bin2hex(random_bytes(8));

    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException(
            "Unable to create test directory: {$directory}"
        );
    }

    return $directory;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);

    if ($entries === false) {
        throw new RuntimeException(
            "Unable to scan test directory: {$directory}"
        );
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($path)) {
            removeDirectory($path);
            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}

$tests = [
    'write and read preserve associative data' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $data = [
                'schema_version' => 1,
                'status' => 'ok',
                'score' => '12.3456',
                'enabled' => true,
                'items' => [
                    'alpha' => '1.2500',
                    'beta' => '2.5000',
                ],
            ];

            $store->write(
                'data.json',
                $data
            );

            assertSameValue(
                $data,
                $store->read('data.json'),
                'Written and read JSON data must be identical.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'write produces valid deterministic pretty JSON' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $store->write(
                'data.json',
                [
                    'schema_version' => 1,
                    'score' => '12.3456',
                    'message' => 'тест',
                ]
            );

            $expected = <<<JSON
{
    "schema_version": 1,
    "score": "12.3456",
    "message": "тест"
}
JSON;

            assertSameValue(
                $expected . PHP_EOL,
                file_get_contents(
                    $directory . DIRECTORY_SEPARATOR . 'data.json'
                ),
                'JSON representation must be deterministic.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'numeric decimal values remain strings' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $data = [
                'raw' => '0.12345678',
                'score' => '0.1235',
                'negative' => '-12.5000',
                'integer_string' => '100',
            ];

            $store->write(
                'data.json',
                $data
            );

            assertSameValue(
                $data,
                $store->read('data.json'),
                'Decimal values must remain strings.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'large JSON integers are preserved as strings' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write(
                'data.json',
                '{"identifier":92233720368547758079223372036854775807}'
            );

            $store = new JsonStore($storage);

            $result = $store->read('data.json');

            assertSameValue(
                '92233720368547758079223372036854775807',
                $result['identifier'],
                'Large JSON integers must not lose precision.'
            );

            assertSameValue(
                'string',
                get_debug_type($result['identifier']),
                'Large JSON integers must be represented as strings.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'integer values remain integers' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $store->write(
                'data.json',
                [
                    'schema_version' => 1,
                    'count' => 42,
                ]
            );

            $result = $store->read('data.json');

            assertSameValue(
                1,
                $result['schema_version'],
                'Schema version must remain an integer.'
            );

            assertSameValue(
                42,
                $result['count'],
                'Integer values must remain integers.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'boolean and null values are preserved' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $data = [
                'enabled' => true,
                'disabled' => false,
                'value' => null,
            ];

            $store->write(
                'data.json',
                $data
            );

            assertSameValue(
                $data,
                $store->read('data.json'),
                'Boolean and null values must be preserved.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'nested arrays are preserved' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $data = [
                'observations' => [
                    [
                        'id' => 'obs-001',
                        'value' => '12.5000',
                    ],
                    [
                        'id' => 'obs-002',
                        'value' => '-4.2500',
                    ],
                ],
            ];

            $store->write(
                'data.json',
                $data
            );

            assertSameValue(
                $data,
                $store->read('data.json'),
                'Nested JSON arrays must be preserved.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'missing file returns null' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            assertSameValue(
                null,
                $store->read('missing.json'),
                'Missing JSON file must return null.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'exists delegates to atomic storage' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            assertFalseValue(
                $store->exists('data.json'),
                'File must not exist before write.'
            );

            $store->write(
                'data.json',
                [
                    'value' => '1',
                ]
            );

            assertTrueValue(
                $store->exists('data.json'),
                'File must exist after write.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'delete delegates to atomic storage' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $store->write(
                'data.json',
                [
                    'value' => '1',
                ]
            );

            $store->delete('data.json');

            assertFalseValue(
                $store->exists('data.json'),
                'File must not exist after delete.'
            );

            assertSameValue(
                null,
                $store->read('data.json'),
                'Deleted file must return null.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'delete of missing file is idempotent' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            $store->delete('missing.json');

            assertFalseValue(
                $store->exists('missing.json'),
                'Missing file must remain absent.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'invalid JSON is rejected' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write(
                'broken.json',
                '{"schema_version":1,'
            );

            $store = new JsonStore($storage);

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->read('broken.json');
                },
                'Malformed JSON must be rejected.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'scalar JSON root is rejected' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write(
                'scalar.json',
                '"value"'
            );

            $store = new JsonStore($storage);

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->read('scalar.json');
                },
                'Scalar JSON roots must be rejected.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'float values are rejected' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->write(
                        'data.json',
                        [
                            'score' => 1.25,
                        ]
                    );
                },
                'Float values must not enter JSON storage.'
            );

            assertFalseValue(
                $store->exists('data.json'),
                'Failed float write must not create the target file.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'nested float values are rejected' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->write(
                        'data.json',
                        [
                            'items' => [
                                'value' => 1.25,
                            ],
                        ]
                    );
                },
                'Nested float values must not enter JSON storage.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'path traversal is rejected' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $store = new JsonStore(
                new AtomicJsonFile($directory)
            );

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->write(
                        '../data.json',
                        [
                            'value' => '1',
                        ]
                    );
                },
                'Path traversal must be rejected by JSON storage.'
            );

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->read('../data.json');
                },
                'Path traversal must be rejected during read.'
            );

            assertThrows(
                RuntimeException::class,
                static function () use ($store): void {
                    $store->delete('../data.json');
                },
                'Path traversal must be rejected during delete.'
            );
        } finally {
            removeDirectory($directory);
        }
    },
];

$passed = 0;

foreach ($tests as $name => $test) {
    $test();
    $passed++;

    echo '[OK] ' . $name . PHP_EOL;
}

echo PHP_EOL;
echo 'ALL JSON STORE TESTS PASSED: ' . $passed . PHP_EOL;