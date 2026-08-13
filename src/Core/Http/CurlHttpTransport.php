<?php

declare(strict_types=1);

namespace MacroRisk\Core\Http;

use RuntimeException;

final class CurlHttpTransport implements HttpTransport
{
    public function __construct(
        private readonly int $connectTimeout = 10,
        private readonly int $timeout = 60,
        private readonly int $maxResponseBytes = 10_485_760
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

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): HttpResponse {
        $this->assertUrl($url);

        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: ext-curl is required.'
            );
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException(
                'SOURCE_TRANSPORT_ERROR: unable to initialize cURL.'
            );
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
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

        curl_close($handle);

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

        return new HttpResponse(
            $status,
            $response
        );
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