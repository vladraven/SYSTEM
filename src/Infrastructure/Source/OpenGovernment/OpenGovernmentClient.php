<?php

declare(strict_types=1);

namespace MacroRisk\Infrastructure\Source\OpenGovernment;

use MacroRisk\Core\Http\HttpClient;
use RuntimeException;

final class OpenGovernmentClient
{
    private const BASE_URL =
        'https://open.canada.ca/data/en/api/3/action';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function packageSearch(
        string $query
    ): array {
        $query = trim($query);

        if ($query === '') {
            throw new RuntimeException(
                'Open Government package search query cannot be empty.'
            );
        }

        $url = self::BASE_URL
            . '/package_search?'
            . http_build_query(
                ['q' => $query],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        return $this->requestAction(
            $url,
            'package_search'
        );
    }

    public function packageShow(
        string $packageId
    ): array {
        $packageId = trim($packageId);

        self::assertIdentifier(
            $packageId,
            'package id'
        );

        $url = self::BASE_URL
            . '/package_show?'
            . http_build_query(
                ['id' => $packageId],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        return $this->requestAction(
            $url,
            'package_show'
        );
    }

    public function dataStoreSearch(
        string $resourceId,
        int $limit = 100,
        int $offset = 0,
        ?string $query = null
    ): array {
        $resourceId = trim($resourceId);

        self::assertIdentifier(
            $resourceId,
            'resource id'
        );

        if ($limit <= 0) {
            throw new RuntimeException(
                'Open Government datastore limit must be positive.'
            );
        }

        if ($limit > 10000) {
            throw new RuntimeException(
                'Open Government datastore limit cannot exceed 10000.'
            );
        }

        if ($offset < 0) {
            throw new RuntimeException(
                'Open Government datastore offset cannot be negative.'
            );
        }

        if ($query !== null) {
            $query = trim($query);

            if ($query === '') {
                throw new RuntimeException(
                    'Open Government datastore query cannot be empty.'
                );
            }
        }

        $params = [
            'resource_id' => $resourceId,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($query !== null) {
            $params['q'] = $query;
        }

        $url = self::BASE_URL
            . '/datastore_search?'
            . http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        return $this->requestAction(
            $url,
            'datastore_search'
        );
    }

    private function requestAction(
        string $url,
        string $action
    ): array {
        $response = $this->http->getJson($url);

        if (!array_key_exists('success', $response)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing CKAN success field for {$action}."
            );
        }

        if (!is_bool($response['success'])) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: CKAN success field is not boolean for {$action}."
            );
        }

        if ($response['success'] !== true) {
            throw new RuntimeException(
                "SOURCE_API_ERROR: CKAN {$action} request was unsuccessful."
            );
        }

        if (!array_key_exists('result', $response)) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: missing CKAN result for {$action}."
            );
        }

        if (!is_array($response['result'])) {
            throw new RuntimeException(
                "SOURCE_SCHEMA_MISMATCH: CKAN result is not an object for {$action}."
            );
        }

        return $response;
    }

    private static function assertIdentifier(
        string $value,
        string $field
    ): void {
        if ($value === '') {
            throw new RuntimeException(
                "Open Government {$field} cannot be empty."
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                "Invalid Open Government {$field}: {$value}"
            );
        }
    }
}