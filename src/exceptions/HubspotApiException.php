<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception raised for HubSpot API transport and response failures.
 */
final class HubspotApiException extends RuntimeException
{
    private readonly int $statusCode;

    private readonly string $responseBody;

    private readonly ?string $correlationId;

    /**
     * @param string $message
     * @param int $statusCode
     * @param string $responseBody
     * @param string|null $correlationId
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        string $responseBody = '',
        ?string $correlationId = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->correlationId = $correlationId;
    }

    /**
     * Return the HTTP status code for the failed HubSpot call.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Return the raw response body for the failed HubSpot call.
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * Return HubSpot correlation ID if available.
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }
}
