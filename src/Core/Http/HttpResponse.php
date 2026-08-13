<?php

declare(strict_types=1);

namespace MacroRisk\Core\Http;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body
    ) {
    }
}