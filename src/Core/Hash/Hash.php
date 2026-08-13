<?php

declare(strict_types=1);

namespace MacroRisk\Core\Hash;

use JsonException;
use RuntimeException;

final class Hash
{
    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function canonicalJson(array $data): string
    {
        self::assertNoFloats($data);

        $normalized = self::normalize($data);

        try {
            return json_encode(
                $normalized,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode canonical JSON.',
                0,
                $exception
            );
        }
    }

    public static function canonical(array $data): string
    {
        return self::sha256(
            self::canonicalJson($data)
        );
    }

    private static function normalize(array $data): array
    {
        if (array_is_list($data)) {
            $normalized = [];

            foreach ($data as $value) {
                $normalized[] = is_array($value)
                    ? self::normalize($value)
                    : $value;
            }

            return $normalized;
        }

        ksort(
            $data,
            SORT_STRING
        );

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            }
        }

        return $data;
    }

    private static function assertNoFloats(
        array $data,
        string $path = '$'
    ): void {
        foreach ($data as $key => $value) {
            $currentPath = $path . '[' . var_export($key, true) . ']';

            if (is_float($value)) {
                throw new RuntimeException(
                    "Float values are not allowed in canonical hashes: {$currentPath}"
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