<?php

declare(strict_types=1);

namespace MacroRisk\Domain\ModelVersion;

use RuntimeException;

final class ModelVersion
{
    public function __construct(
        private readonly string $version,
        private readonly string $description
    ) {
        self::assertVersion($version);
        self::assertDescription($description);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): string
    {
        return $this->description;
    }

    private static function assertVersion(
        string $version
    ): void {
        if ($version === '') {
            throw new RuntimeException(
                'Model version cannot be empty.'
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $version
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid model version: {$version}"
            );
        }
    }

    private static function assertDescription(
        string $description
    ): void {
        if (trim($description) === '') {
            throw new RuntimeException(
                'Model version description cannot be empty.'
            );
        }
    }
}