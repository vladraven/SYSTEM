<?php

declare(strict_types=1);

use MacroRisk\Core\Storage\AtomicJsonFile;
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

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
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

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertFalseValue(bool $condition, string $message): void
{
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
            . 'Actual exception: ' . $exception::class
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
        . 'macrorisk-atomic-'
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
    'constructor rejects empty directory' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                new AtomicJsonFile('');
            },
            'Empty directory must be rejected.'
        );
    },

    'missing directory is created on write' => static function (): void {
        $root = createTemporaryDirectory();

        try {
            $directory = $root . DIRECTORY_SEPARATOR . 'nested';
            $storage = new AtomicJsonFile($directory);

            assertFalseValue(
                is_dir($directory),
                'Test directory must initially be absent.'
            );

            $storage->write(
                'data.json',
                '{"schema_version":1}'
            );

            assertTrueValue(
                is_dir($directory),
                'Write must create the missing directory.'
            );

            assertTrueValue(
                is_file(
                    $directory . DIRECTORY_SEPARATOR . 'data.json'
                ),
                'Write must create the target file.'
            );
        } finally {
            removeDirectory($root);
        }
    },

    'write creates and read returns exact contents' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);
            $contents = "{\n    \"schema_version\": 1\n}";

            $storage->write('data.json', $contents);

            assertTrueValue(
                $storage->exists('data.json'),
                'exists() must return true after a successful write.'
            );

            assertSameValue(
                $contents,
                $storage->read('data.json'),
                'read() must return the exact stored contents.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'read returns null for missing file' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            assertSameValue(
                null,
                $storage->read('missing.json'),
                'read() must return null when the target does not exist.'
            );

            assertFalseValue(
                $storage->exists('missing.json'),
                'exists() must return false for a missing file.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'write replaces existing file' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write(
                'data.json',
                '{"value":"first"}'
            );

            $storage->write(
                'data.json',
                '{"value":"second"}'
            );

            assertSameValue(
                '{"value":"second"}',
                $storage->read('data.json'),
                'Second write must replace the previous contents.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'successful write leaves no temporary files' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write(
                'data.json',
                '{"value":"stable"}'
            );

            $entries = scandir($directory);

            if ($entries === false) {
                throw new RuntimeException(
                    'Unable to inspect atomic storage directory.'
                );
            }

            $temporaryFiles = array_values(
                array_filter(
                    $entries,
                    static fn (string $entry): bool =>
                        str_starts_with($entry, '.atomic-')
                )
            );

            assertSameValue(
                [],
                $temporaryFiles,
                'Successful atomic write must not leave temporary files.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'empty contents are written exactly' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write('empty.json', '');

            assertTrueValue(
                $storage->exists('empty.json'),
                'Empty content must still create the target file.'
            );

            assertSameValue(
                '',
                $storage->read('empty.json'),
                'Empty content must be preserved exactly.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'delete removes an existing file' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->write(
                'data.json',
                '{"value":"delete-me"}'
            );

            assertTrueValue(
                $storage->exists('data.json'),
                'File must exist before deletion.'
            );

            $storage->delete('data.json');

            assertFalseValue(
                $storage->exists('data.json'),
                'File must not exist after deletion.'
            );

            assertSameValue(
                null,
                $storage->read('data.json'),
                'Deleted file must be unreadable.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'delete of missing file is idempotent' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $storage->delete('missing.json');

            assertFalseValue(
                $storage->exists('missing.json'),
                'Deleting a missing file must leave it missing.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'filename path traversal is rejected' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $invalidFilenames = [
                '../data.json',
                '..\\data.json',
                'nested/data.json',
                'nested\\data.json',
                '.',
                '..',
                '',
            ];

            foreach ($invalidFilenames as $filename) {
                assertThrows(
                    RuntimeException::class,
                    static function () use (
                        $storage,
                        $filename
                    ): void {
                        $storage->write($filename, '{}');
                    },
                    "Invalid filename must be rejected: {$filename}"
                );
            }
        } finally {
            removeDirectory($directory);
        }
    },

    'read rejects filename path traversal' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            assertThrows(
                RuntimeException::class,
                static function () use ($storage): void {
                    $storage->read('../data.json');
                },
                'read() must reject path traversal.'
            );

            assertThrows(
                RuntimeException::class,
                static function () use ($storage): void {
                    $storage->exists('../data.json');
                },
                'exists() must reject path traversal.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'delete rejects filename path traversal' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            assertThrows(
                RuntimeException::class,
                static function () use ($storage): void {
                    $storage->delete('../data.json');
                },
                'delete() must reject path traversal.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'nested directories are not accepted as filenames' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            assertThrows(
                RuntimeException::class,
                static function () use ($storage): void {
                    $storage->write(
                        'subdirectory/data.json',
                        '{}'
                    );
                },
                'AtomicJsonFile must operate on filenames, not nested paths.'
            );
        } finally {
            removeDirectory($directory);
        }
    },

    'stored content remains deterministic' => static function (): void {
        $directory = createTemporaryDirectory();

        try {
            $storage = new AtomicJsonFile($directory);

            $contents = '{"a":"1","b":"2","schema_version":1}';

            $storage->write('data.json', $contents);

            $firstRead = $storage->read('data.json');

            $storage->write('data.json', $contents);

            $secondRead = $storage->read('data.json');

            assertSameValue(
                $firstRead,
                $secondRead,
                'Writing identical contents must produce identical stored contents.'
            );

            assertSameValue(
                $contents,
                $secondRead,
                'Stored contents must not be transformed by the storage primitive.'
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
echo 'ALL ATOMIC JSON FILE TESTS PASSED: ' . $passed . PHP_EOL;