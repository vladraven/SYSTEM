<?php

declare(strict_types=1);

namespace MacroRisk\Core\Storage;

use JsonException;
use RuntimeException;

final class JsonStore
{
    public function __construct(
        private readonly AtomicJsonFile $storage
    ) {
    }

    public function write(
        string $filename,
        array $data
    ): void {
        try {
            $json = json_encode(
                $data,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT
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
                JSON_THROW_ON_ERROR
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
}