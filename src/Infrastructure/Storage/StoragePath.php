<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Storage;

use RuntimeException;

final class StoragePath
{
    public static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function storageRoot(?string $storageRoot = null): string
    {
        return rtrim(
            $storageRoot ?? self::projectRoot() . DIRECTORY_SEPARATOR . 'storage',
            "\/"
        );
    }

    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }
}
