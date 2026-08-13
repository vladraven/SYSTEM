<?php

declare(strict_types=1);

namespace MacroRisk\Core\Storage;

use JsonException;
use RuntimeException;

final class JsonStore
{
    private const JSON_ENCODE_FLAGS =
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT;

    private const JSON_DECODE_FLAGS =
        JSON_THROW_ON_ERROR
        | JSON_BIGINT_AS_STRING;

    public function __construct(
        private readonly AtomicJsonFile $storage
    ) {
    }

    public function write(
        string $filename,
        array $data
    ): void {
        self::assertNoFloats($data);

        try {
            $json = json_encode(
                $data,
                self::JSON_ENCODE_FLAGS
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode JSON data.',
                0,
                $exception
            );
        }

        $this->storage->write(
            $filename,
            $json . PHP_EOL
        );
    }

    public function read(string $filename): ?array
    {
        $json = $this->storage->read($filename);

        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode(
                $json,
                true,
                512,
                self::JSON_DECODE_FLAGS
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Invalid JSON in storage file: {$filename}",
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                "JSON root must be an object or array: {$filename}"
            );
        }

        self::assertNoFloats($data);

        return $data;
    }

    public function exists(string $filename): bool
    {
        return $this->storage->exists($filename);
    }

    public function delete(string $filename): void
    {
        $this->storage->delete($filename);
    }

    private static function assertNoFloats(
        array $data,
        string $path = '$'
    ): void {
        foreach ($data as $key => $value) {
            $currentPath = $path . '[' . var_export($key, true) . ']';

            if (is_float($value)) {
                throw new RuntimeException(
                    "Float values are not allowed in JSON storage: {$currentPath}"
                );
            }

            if (is_array($value)) {
                self::assertNoFloats(
                    $value,
                    $currentPath
                );
            }
        }
    }
}