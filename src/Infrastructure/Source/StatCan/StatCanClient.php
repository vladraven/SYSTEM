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

        self::assertNumericId(
            $productId,
            'product'
        );

        return $this->get(
            '/getFullTableDownloadList/' . $productId
        );
    }

    public function getBulkDownloadFileList(
        string $productId
    ): array {
        $productId = trim($productId);

        self::assertNumericId(
            $productId,
            'product'
        );

        return $this->get(
            '/getBulkDownloadFileList/' . $productId
        );
    }

    public function getSeriesInfo(
        string $vectorId
    ): array {
        $vectorId = trim($vectorId);

        self::assertNumericId(
            $vectorId,
            'vector'
        );

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

        self::assertNumericId(
            $vectorId,
            'vector'
        );

        self::assertReferencePeriod(
            $startReferencePeriod
        );

        self::assertReferencePeriod(
            $endReferencePeriod
        );

        if ($startReferencePeriod > $endReferencePeriod) {
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

        self::assertNumericId(
            $vectorId,
            'vector'
        );

        self::assertReferencePeriod(
            $referencePeriod
        );

        return $this->get(
            '/getDataFromVectorByReferencePeriod'
            . '/' . $vectorId
            . '/' . rawurlencode($referencePeriod)
        );
    }

    private function get(string $path): array
    {
        return $this->http->getJson(
            self::BASE_URL
            . '/'
            . ltrim($path, '/')
        );
    }

    private static function assertNumericId(
        string $value,
        string $type
    ): void {
        if ($value === '') {
            throw new RuntimeException(
                "StatCan {$type} ID cannot be empty."
            );
        }

        if (!preg_match('/^\d+$/', $value)) {
            throw new RuntimeException(
                "Invalid StatCan {$type} ID: {$value}"
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