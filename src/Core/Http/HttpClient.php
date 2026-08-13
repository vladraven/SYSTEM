<?php

declare(strict_types=1);

namespace MacroRisk\Core\Http;

use JsonException;
use RuntimeException;

final class HttpClient
{
    private const DEFAULT_CONNECT_TIMEOUT = 10;
    private const DEFAULT_TIMEOUT = 60;
    private const DEFAULT_MAX_RESPONSE_BYTES = 10_485_760;

    private const USER_AGENT =
        'MacroRisk/2.0 (+https://github.com/vladraven/system)';

    public function __construct(
        private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES
    ) {
        if ($connectTimeout < 1) {
            throw new RuntimeException(
                'HTTP connect timeout must be greater than zero.'
            );
        }

        if ($timeout < 1) {
            throw new RuntimeException(
                'HTTP timeout must be greater than zero.'
            );
        }

        if ($timeout < $connectTimeout) {
            throw new RuntimeException(
                'HTTP timeout cannot be smaller than connect timeout.'
            );
        }

        if ($maxResponseBytes < 1) {
            throw new RuntimeException(
                'HTTP maximum response size must be greater than zero.'
            );
        }
    }

    public function getJson(string $url): array
    {
        $response = $this->request(
            'GET',
            $url,
            [
                'Accept: application/json',
            ]
        );

        try {
            $decoded = json_decode(
                $response['body'],
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: invalid JSON response.',
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: JSON root is not an object/array.'
            );
        }

        return $decoded;
    }

    public function postJson(
        string $url,
        array $payload
    ): array {
        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode HTTP JSON request.',
                0,
                $exception
            );
        }

        $response = $this->request(
            'POST',
            $url,
            [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            $body
        );

        try {
            $decoded = json_decode(
                $response['body'],
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: invalid JSON response.',
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'SOURCE_SCHEMA_MISMATCH: JSON root is not an object/array.'
            );
        }

        return $decoded;
    }

    public function get(string $url): string
    {
        $response = $this->request(
            'GET',
            $url,
            [
                'Accept: application/json',
            ]
        );

        return $response['body'];
    }

    private function request(
        string $method,
        string $url,
        array $headers,
        ?string $body = null
    ): array {
        $this->assertUrl($url);

        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'ext-curl is required.'
            );
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: unable to initialize cURL.'
            );
        }

        $requestHeaders = array_merge(
            [
                'User-Agent: ' . self::USER_AGENT,
            ],
            $headers
        );

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        if (!curl_setopt_array($handle, $options)) {
            curl_close($handle);

            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: unable to configure cURL.'
            );
        }

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);

            curl_close($handle);

            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: ' . $error
            );
        }

        $status = curl_getinfo(
            $handle,
            CURLINFO_RESPONSE_CODE
        );

        $contentLength = curl_getinfo(
            $handle,
            CURLINFO_CONTENT_LENGTH_DOWNLOAD
        );

        curl_close($handle);

        if (
            is_int($contentLength)
            && $contentLength > $this->maxResponseBytes
        ) {
            throw new RuntimeException(
                'SOURCE_RESPONSE_TOO_LARGE: response exceeds maximum size.'
            );
        }

        if (!is_string($response)) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: invalid cURL response.'
            );
        }

        if (strlen($response) > $this->maxResponseBytes) {
            throw new RuntimeException(
                'SOURCE_RESPONSE_TOO_LARGE: response exceeds maximum size.'
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                "SOURCE_HTTP_ERROR: HTTP {$status}"
            );
        }

        return [
            'status' => $status,
            'body' => $response,
        ];
    }

    private function assertUrl(string $url): void
    {
        if ($url === '') {
            throw new RuntimeException(
                'HTTP URL cannot be empty.'
            );
        }

        $parts = parse_url($url);

        if ($parts === false) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: invalid URL.'
            );
        }

        if (
            !isset($parts['scheme'])
            || strtolower($parts['scheme']) !== 'https'
        ) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: HTTPS is required.'
            );
        }

        if (
            !isset($parts['host'])
            || $parts['host'] === ''
        ) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: URL host is required.'
            );
        }
    }
}