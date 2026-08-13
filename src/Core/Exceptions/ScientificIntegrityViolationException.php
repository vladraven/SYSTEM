<?php

declare(strict_types=1);

namespace MacroRisk\Core\Exceptions;

use Exception;
use Throwable;

/**
 * MacroRisk Scientific Integrity Violation Exception (v1.5.0-comprehensive)
 *
 * Exception thrown when a narrative, report, or model interpretation contains
 * unverified predictive claims, speculative wave theories, or banned phrases.
 */
class ScientificIntegrityViolationException extends Exception
{
    private string $errorCode;
    private int $httpStatus;

    public function __construct(
        string $message = "Scientific integrity violation detected.",
        int $httpStatus = 422,
        string $errorCode = "SCIENTIFIC_INTEGRITY_VIOLATION",
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}