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
        private readonly HttpTransport $transport
    ) {
    }

    public static function production(
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES
    ): self {
        return new self(
            new CurlHttpTransport(
                $connectTimeout,
                $timeout,
                $maxResponseBytes
            )
        );
    }

    public function getJson(string $url): array
    {
        $response = $this->transport->request(
            'GET',
            $url,
            [
                'Accept: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ]
        );

        $this->assertSuccessfulResponse($response);

        try {
            $decoded = json_decode(
                $response->body,
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

        $response = $this->transport->request(
            'POST',
            $url,
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ],
            $body
        );

        $this->assertSuccessfulResponse($response);

        try {
            $decoded = json_decode(
                $response->body,
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
        $response = $this->transport->request(
            'GET',
            $url,
            [
                'Accept: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ]
        );

        $this->assertSuccessfulResponse($response);

        return $response->body;
    }

    private function assertSuccessfulResponse(
        HttpResponse $response
    ): void {
        if ($response->status < 200 || $response->status >= 300) {
            throw new RuntimeException(
                "SOURCE_HTTP_ERROR: HTTP {$response->status}"
            );
        }
    }
}