<?php

declare(strict_types=1);

namespace MacroRisk\Core\Storage;

use RuntimeException;

final class AtomicJsonFile
{
    public function __construct(
        private readonly string $directory
    ) {
        if ($directory === '') {
            throw new RuntimeException(
                'Atomic JSON file directory cannot be empty.'
            );
        }
    }

    public function write(string $filename, string $contents): void
    {
        $this->assertFilename($filename);

        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new RuntimeException(
                    "Unable to create directory: {$this->directory}"
                );
            }
        }

        if (!is_writable($this->directory)) {
            throw new RuntimeException(
                "Directory is not writable: {$this->directory}"
            );
        }

        $target = $this->directory . DIRECTORY_SEPARATOR . $filename;
        $temporary = tempnam($this->directory, '.atomic-');

        if ($temporary === false) {
            throw new RuntimeException(
                "Unable to create temporary file in: {$this->directory}"
            );
        }

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            @unlink($temporary);

            throw new RuntimeException(
                "Unable to open temporary file: {$temporary}"
            );
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(
                    "Unable to acquire exclusive lock: {$temporary}"
                );
            }

            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $result = fwrite(
                    $handle,
                    substr($contents, $written)
                );

                if ($result === false || $result === 0) {
                    throw new RuntimeException(
                        "Unable to write temporary file: {$temporary}"
                    );
                }

                $written += $result;
            }

            if (!fflush($handle)) {
                throw new RuntimeException(
                    "Unable to flush temporary file: {$temporary}"
                );
            }

            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException(
                    "Unable to synchronize temporary file: {$temporary}"
                );
            }

            flock($handle, LOCK_UN);
            fclose($handle);
            $handle = null;

            if (!rename($temporary, $target)) {
                throw new RuntimeException(
                    "Unable to atomically replace file: {$target}"
                );
            }

            $temporary = null;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }

            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function read(string $filename): ?string
    {
        $this->assertFilename($filename);

        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                "Unable to open file for reading: {$path}"
            );
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException(
                    "Unable to acquire shared lock: {$path}"
                );
            }

            $contents = stream_get_contents($handle);

            if ($contents === false) {
                throw new RuntimeException(
                    "Unable to read file: {$path}"
                );
            }

            flock($handle, LOCK_UN);

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    public function exists(string $filename): bool
    {
        $this->assertFilename($filename);

        return is_file(
            $this->directory . DIRECTORY_SEPARATOR . $filename
        );
    }

    private function assertFilename(string $filename): void
    {
        if ($filename === '') {
            throw new RuntimeException(
                'Atomic JSON filename cannot be empty.'
            );
        }

        if (
            $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
        ) {
            throw new RuntimeException(
                "Invalid atomic JSON filename: {$filename}"
            );
        }
    }
}