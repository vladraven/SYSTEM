<?php

declare(strict_types=1);

use MacroRisk\Core\Hash\CanonicalHasher;
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

function assertNotSameValue(
    mixed $first,
    mixed $second,
    string $message
): void {
    if ($first === $second) {
        throw new RuntimeException(
            $message
            . PHP_EOL
            . 'Both values: ' . var_export($first, true)
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

$tests = [
    'sha256 hashes raw strings deterministically' => static function (): void {
        assertSameValue(
            hash('sha256', 'MacroRisk'),
            CanonicalHasher::sha256('MacroRisk'),
            'Raw SHA-256 hash must match PHP SHA-256.'
        );
    },

    'sha256 returns lowercase hexadecimal digest' => static function (): void {
        $hash = CanonicalHasher::sha256('MacroRisk');

        assertSameValue(
            64,
            strlen($hash),
            'SHA-256 digest must contain 64 hexadecimal characters.'
        );

        assertTrueValue(
            preg_match('/^[0-9a-f]{64}$/', $hash) === 1,
            'SHA-256 digest must use lowercase hexadecimal representation.'
        );
    },

    'canonical json sorts object keys' => static function (): void {
        assertSameValue(
            '{"a":"1","b":"2"}',
            CanonicalHasher::canonicalJson([
                'b' => '2',
                'a' => '1',
            ]),
            'Canonical JSON must sort object keys.'
        );
    },

    'canonical json sorts nested object keys' => static function (): void {
        assertSameValue(
            '{"a":{"x":"1","y":"2"},"b":"3"}',
            CanonicalHasher::canonicalJson([
                'b' => '3',
                'a' => [
                    'y' => '2',
                    'x' => '1',
                ],
            ]),
            'Canonical JSON must recursively sort object keys.'
        );
    },

    'canonical json preserves list order' => static function (): void {
        assertSameValue(
            '{"items":["b","a","c"]}',
            CanonicalHasher::canonicalJson([
                'items' => [
                    'b',
                    'a',
                    'c',
                ],
            ]),
            'Canonical JSON must preserve list order.'
        );
    },

    'equivalent object order produces identical hash' => static function (): void {
        $first = [
            'model_version' => '1',
            'score' => '12.5000',
            'coverage' => '75.0000',
        ];

        $second = [
            'coverage' => '75.0000',
            'model_version' => '1',
            'score' => '12.5000',
        ];

        assertSameValue(
            CanonicalHasher::hash($first),
            CanonicalHasher::hash($second),
            'Equivalent object key order must produce identical hashes.'
        );
    },

    'equivalent nested object order produces identical hash' => static function (): void {
        $first = [
            'indicator' => [
                'key' => 'unemployment',
                'score' => '25.0000',
            ],
            'status' => 'ok',
        ];

        $second = [
            'status' => 'ok',
            'indicator' => [
                'score' => '25.0000',
                'key' => 'unemployment',
            ],
        ];

        assertSameValue(
            CanonicalHasher::hash($first),
            CanonicalHasher::hash($second),
            'Nested object key order must not affect canonical hash.'
        );
    },

    'list order changes canonical hash' => static function (): void {
        $first = [
            'items' => [
                'a',
                'b',
            ],
        ];

        $second = [
            'items' => [
                'b',
                'a',
            ],
        ];

        assertNotSameValue(
            CanonicalHasher::hash($first),
            CanonicalHasher::hash($second),
            'List order must remain semantically significant.'
        );
    },

    'value changes canonical hash' => static function (): void {
        assertNotSameValue(
            CanonicalHasher::hash([
                'score' => '12.5000',
            ]),
            CanonicalHasher::hash([
                'score' => '12.5001',
            ]),
            'Changing a value must change the canonical hash.'
        );
    },

    'types remain significant' => static function (): void {
        assertNotSameValue(
            CanonicalHasher::hash([
                'value' => '1',
            ]),
            CanonicalHasher::hash([
                'value' => 1,
            ]),
            'String and integer values must produce different hashes.'
        );

        assertNotSameValue(
            CanonicalHasher::hash([
                'value' => true,
            ]),
            CanonicalHasher::hash([
                'value' => 1,
            ]),
            'Boolean and integer values must produce different hashes.'
        );
    },

    'unicode is not escaped' => static function (): void {
        assertSameValue(
            '{"message":"Канада"}',
            CanonicalHasher::canonicalJson([
                'message' => 'Канада',
            ]),
            'Canonical JSON must preserve Unicode characters.'
        );
    },

    'slashes are not escaped' => static function (): void {
        assertSameValue(
            '{"source":"https://example.test/data"}',
            CanonicalHasher::canonicalJson([
                'source' => 'https://example.test/data',
            ]),
            'Canonical JSON must not escape slashes.'
        );
    },

    'float values are rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                CanonicalHasher::hash([
                    'score' => 12.5,
                ]);
            },
            'Float values must not enter canonical hashes.'
        );
    },

    'nested float values are rejected' => static function (): void {
        assertThrows(
            RuntimeException::class,
            static function (): void {
                CanonicalHasher::hash([
                    'indicator' => [
                        'score' => 12.5,
                    ],
                ]);
            },
            'Nested float values must not enter canonical hashes.'
        );
    },

    'canonical hash equals sha256 of canonical json' => static function (): void {
        $data = [
            'schema_version' => 1,
            'model_version' => '1.0.0',
            'risk_score' => '42.5000',
        ];

        assertSameValue(
            CanonicalHasher::sha256(
                CanonicalHasher::canonicalJson($data)
            ),
            CanonicalHasher::hash($data),
            'Canonical hash must be SHA-256 of canonical JSON.'
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
echo 'ALL CANONICAL HASHER TESTS PASSED: ' . $passed . PHP_EOL;