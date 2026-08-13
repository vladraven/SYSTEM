<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Source\StatCan;

use MacroRisk\Core\Http\HttpClient;
use RuntimeException;

final class StatCanClient
{
    private const BASE_URL =
        'https://www150.statcan.gc.ca/t1/wds/rest';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function getChangedSeriesList(): array
    {
        return $this->get(
            '/getChangedSeriesList'
        );
    }

    public function getFullTableDownloadList(
        string $productId
    ): array {
        $productId = trim($productId);

        if ($productId === '') {
            throw new RuntimeException(
                'StatCan product ID cannot be empty.'
            );
        }

        if (!preg_match('/^\d+$/', $productId)) {
            throw new RuntimeException(
                "Invalid StatCan product ID: {$productId}"
            );
        }

        return $this->get(
            '/getFullTableDownloadList/' . $productId
        );
    }

    public function getBulkDownloadFileList(
        string $productId
    ): array {
        $productId = trim($productId);

        if ($productId === '') {
            throw new RuntimeException(
                'StatCan product ID cannot be empty.'
            );
        }

        if (!preg_match('/^\d+$/', $productId)) {
            throw new RuntimeException(
                "Invalid StatCan product ID: {$productId}"
            );
        }

        return $this->get(
            '/getBulkDownloadFileList/' . $productId
        );
    }

    public function getSeriesInfo(
        string $vectorId
    ): array {
        $vectorId = trim($vectorId);

        if ($vectorId === '') {
            throw new RuntimeException(
                'StatCan vector ID cannot be empty.'
            );
        }

        if (!preg_match('/^\d+$/', $vectorId)) {
            throw new RuntimeException(
                "Invalid StatCan vector ID: {$vectorId}"
            );
        }

        return $this->get(
            '/getSeriesInfoFromVectorByReferencePeriod'
            . '/' . $vectorId
        );
    }

    public function getDataFromVectorByReferencePeriodRange(
        string $vectorId,
        string $startReferencePeriod,
        string $endReferencePeriod
    ): array {
        $vectorId = trim($vectorId);
        $startReferencePeriod = trim($startReferencePeriod);
        $endReferencePeriod = trim($endReferencePeriod);

        self::assertVectorId($vectorId);
        self::assertReferencePeriod(
            $startReferencePeriod
        );
        self::assertReferencePeriod(
            $endReferencePeriod
        );

        if (
            $startReferencePeriod
            > $endReferencePeriod
        ) {
            throw new RuntimeException(
                'StatCan start reference period cannot be after end reference period.'
            );
        }

        return $this->get(
            '/getDataFromVectorByReferencePeriodRange'
            . '/' . $vectorId
            . '/' . rawurlencode($startReferencePeriod)
            . '/' . rawurlencode($endReferencePeriod)
        );
    }

    public function getDataFromVectorByReferencePeriod(
        string $vectorId,
        string $referencePeriod
    ): array {
        $vectorId = trim($vectorId);
        $referencePeriod = trim($referencePeriod);

        self::assertVectorId($vectorId);
        self::assertReferencePeriod($referencePeriod);

        return $this->get(
            '/getDataFromVectorByReferencePeriod'
            . '/' . $vectorId
            . '/' . rawurlencode($referencePeriod)
        );
    }

    private function get(string $path): array
    {
        $path = '/' . ltrim($path, '/');

        return $this->http->getJson(
            self::BASE_URL . $path
        );
    }

    private static function assertVectorId(
        string $vectorId
    ): void {
        if ($vectorId === '') {
            throw new RuntimeException(
                'StatCan vector ID cannot be empty.'
            );
        }

        if (!preg_match('/^\d+$/', $vectorId)) {
            throw new RuntimeException(
                "Invalid StatCan vector ID: {$vectorId}"
            );
        }
    }

    private static function assertReferencePeriod(
        string $referencePeriod
    ): void {
        if ($referencePeriod === '') {
            throw new RuntimeException(
                'StatCan reference period cannot be empty.'
            );
        }

        if (!preg_match(
            '/^\d{4}(?:-\d{2})?$/',
            $referencePeriod
        )) {
            throw new RuntimeException(
                "Invalid StatCan reference period: {$referencePeriod}"
            );
        }
    }
}