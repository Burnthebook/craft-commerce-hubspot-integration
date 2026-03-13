<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\services;

use JsonException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use burnthebook\craftcommercehubspotintegration\enums\HubspotObjectType;
use burnthebook\craftcommercehubspotintegration\exceptions\HubspotApiException;
use burnthebook\craftcommercehubspotintegration\enums\HubspotAssociationCategory;

/**
 * Thin HTTP client for HubSpot API requests.
 *
 * This class is intentionally transport-focused (auth, request execution,
 * response parsing). Domain-specific HubSpot workflows will live in a
 * separate service layer.
 */
final class HubspotApiClient
{
    private const int MAX_RETRY_ATTEMPTS = 3;

    private const int BASE_RETRY_DELAY_MS = 200;

    private readonly ClientInterface $httpClient;

    /**
     * @param string $baseUri HubSpot API base URI.
     * @param string|null $accessToken Private app access token (Bearer).
     * @param ClientInterface|null $httpClient Optional injected Guzzle client.
     */
    public function __construct(
        string $baseUri = 'https://api.hubapi.com',
        ?string $accessToken = null,
        ?ClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($baseUri, '/') . '/',
            'headers' => $this->buildDefaultHeaders($accessToken),
            'http_errors' => false,
        ]);
    }

    /**
     * Execute a HubSpot API request and decode the JSON response.
     *
     * @param string $method HTTP method (GET, POST, PATCH, PUT, DELETE).
     * @param string $path Relative API path (for example: /crm/v3/objects/contacts).
     * @param array<string, mixed> $requestOptions Guzzle request options (json, query, headers, etc).
     *
     * @return array<int|string, mixed>
     *
     * @throws HubspotApiException When request transport fails, HubSpot returns non-2xx,
     * or response body is invalid JSON.
     */
    public function request(string $method, string $path, array $requestOptions = []): array
    {
        if (isset($requestOptions['json'])) {
            if (!isset($requestOptions['headers']) || !is_array($requestOptions['headers'])) {
                $requestOptions['headers'] = [];
            }

            $requestOptions['headers']['Content-Type'] ??= 'application/json';
        }

        $response = $this->sendRequestWithRetries(
            method: strtoupper($method),
            path: ltrim($path, '/'),
            requestOptions: $requestOptions
        );

        $this->ensureSuccessfulResponse($response);

        $body = (string)$response->getBody();
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HubspotApiException(
                'HubSpot API response is not valid JSON.',
                statusCode: $response->getStatusCode(),
                responseBody: $body,
                correlationId: $this->extractCorrelationId(response: $response, responseBody: $body),
                previous: $exception
            );
        }

        if (!is_array($decoded)) {
            throw new HubspotApiException(
                'HubSpot API response JSON root must decode to an array/object.',
                statusCode: $response->getStatusCode(),
                responseBody: $body,
                correlationId: $this->extractCorrelationId(response: $response, responseBody: $body)
            );
        }

        return $decoded;
    }

    /**
     * Execute a GET request.
     *
     * @param string $path Relative API path.
     * @param array<string, mixed> $requestOptions
     *
     * @return array<int|string, mixed>
     */
    public function get(string $path, array $requestOptions = []): array
    {
        return $this->request(method: 'GET', path: $path, requestOptions: $requestOptions);
    }

    /**
     * Execute a POST request.
     *
     * @param string $path Relative API path.
     * @param array<string, mixed> $requestOptions
     *
     * @return array<int|string, mixed>
     */
    public function post(string $path, array $requestOptions = []): array
    {
        return $this->request(method: 'POST', path: $path, requestOptions: $requestOptions);
    }

    /**
     * Execute a PATCH request.
     *
     * @param string $path Relative API path.
     * @param array<string, mixed> $requestOptions
     *
     * @return array<int|string, mixed>
     */
    public function patch(string $path, array $requestOptions = []): array
    {
        return $this->request(method: 'PATCH', path: $path, requestOptions: $requestOptions);
    }

    /**
     * Execute a PUT request.
     *
     * @param string $path Relative API path.
     * @param array<string, mixed> $requestOptions
     *
     * @return array<int|string, mixed>
     */
    public function put(string $path, array $requestOptions = []): array
    {
        return $this->request(method: 'PUT', path: $path, requestOptions: $requestOptions);
    }

    /**
     * Execute a DELETE request.
     *
     * @param string $path Relative API path.
     * @param array<string, mixed> $requestOptions
     *
     * @return array<int|string, mixed>
     */
    public function delete(string $path, array $requestOptions = []): array
    {
        return $this->request(method: 'DELETE', path: $path, requestOptions: $requestOptions);
    }

    /**
     * Search CRM objects via HubSpot's object search endpoint.
     *
     * @param string|HubspotObjectType $objectType HubSpot object type.
     * @param array<int, array<string, mixed>> $filterGroups Search filter groups.
     * @param array<int, string> $properties Properties to return.
     * @param int $limit Maximum records to return.
     * @param string|null $after Optional paging cursor.
     *
     * @return array<int|string, mixed>
     */
    public function searchObjects(
        string|HubspotObjectType $objectType,
        array $filterGroups,
        array $properties = [],
        int $limit = 10,
        ?string $after = null
    ): array {
        /** @var array<string, mixed> $payload */
        $payload = [
            'filterGroups' => $filterGroups,
            'limit' => $limit,
        ];

        if ($properties !== []) {
            $payload['properties'] = $properties;
        }

        if ($after !== null && $after !== '') {
            $payload['after'] = $after;
        }

        return $this->post(
            path: sprintf('/crm/v3/objects/%s/search', $this->normalizeObjectType($objectType)),
            requestOptions: ['json' => $payload]
        );
    }

    /**
     * Create a CRM object in HubSpot.
     *
     * @param string|HubspotObjectType $objectType HubSpot object type.
     * @param array<string, scalar|null> $properties Object properties.
     * @param array<int, array<string, mixed>> $associations Optional associations payload.
     *
     * @return array<int|string, mixed>
     */
    public function createObject(string|HubspotObjectType $objectType, array $properties, array $associations = []): array
    {
        /** @var array<string, mixed> $payload */
        $payload = [
            'properties' => $properties,
        ];

        if ($associations !== []) {
            $payload['associations'] = $associations;
        }

        return $this->post(
            path: sprintf('/crm/v3/objects/%s', $this->normalizeObjectType($objectType)),
            requestOptions: ['json' => $payload]
        );
    }

    /**
     * Update a CRM object in HubSpot.
     *
     * @param string|HubspotObjectType $objectType HubSpot object type.
     * @param string $objectId HubSpot object ID.
     * @param array<string, scalar|null> $properties Object properties.
     *
     * @return array<int|string, mixed>
     */
    public function updateObject(string|HubspotObjectType $objectType, string $objectId, array $properties): array
    {
        return $this->patch(
            path: sprintf('/crm/v3/objects/%s/%s', $this->normalizeObjectType($objectType), $objectId),
            requestOptions: [
                'json' => [
                    'properties' => $properties,
                ],
            ]
        );
    }

    /**
     * Associate two HubSpot CRM objects via the v4 associations API.
     *
     * @param string|HubspotObjectType $fromObjectType Source object type.
     * @param string $fromObjectId Source object ID.
     * @param string|HubspotObjectType $toObjectType Target object type.
     * @param string $toObjectId Target object ID.
     * @param array<int, array{associationCategory: string, associationTypeId: int}> $associationTypes
     *
     * @return array<int|string, mixed>
     */
    public function associateObjects(
        string|HubspotObjectType $fromObjectType,
        string $fromObjectId,
        string|HubspotObjectType $toObjectType,
        string $toObjectId,
        array $associationTypes
    ): array {
        return $this->put(
            path: sprintf(
                '/crm/v4/objects/%s/%s/associations/%s/%s',
                $this->normalizeObjectType($fromObjectType),
                $fromObjectId,
                $this->normalizeObjectType($toObjectType),
                $toObjectId
            ),
            requestOptions: [
                'json' => $associationTypes,
            ]
        );
    }

    /**
     * Associate two HubSpot CRM objects via one association type.
     *
     * @param string|HubspotObjectType $fromObjectType
     * @param string $fromObjectId
     * @param string|HubspotObjectType $toObjectType
     * @param string $toObjectId
     * @param int $associationTypeId
     * @param string|HubspotAssociationCategory $associationCategory
     *
     * @return array<int|string, mixed>
     */
    public function associateObjectsByType(
        string|HubspotObjectType $fromObjectType,
        string $fromObjectId,
        string|HubspotObjectType $toObjectType,
        string $toObjectId,
        int $associationTypeId,
        string|HubspotAssociationCategory $associationCategory = HubspotAssociationCategory::UserDefined
    ): array {
        $resolvedAssociationCategory = $associationCategory instanceof HubspotAssociationCategory
            ? $associationCategory->value
            : $associationCategory;

        return $this->associateObjects(
            fromObjectType: $fromObjectType,
            fromObjectId: $fromObjectId,
            toObjectType: $toObjectType,
            toObjectId: $toObjectId,
            associationTypes: [[
                'associationCategory' => $resolvedAssociationCategory,
                'associationTypeId' => $associationTypeId,
            ]]
        );
    }

    /**
     * Normalize object type input to a HubSpot object type string.
     *
     * @param string|HubspotObjectType $objectType
     *
     * @return string
     */
    private function normalizeObjectType(string|HubspotObjectType $objectType): string
    {
        if ($objectType instanceof HubspotObjectType) {
            return $objectType->value;
        }

        return trim($objectType, '/');
    }

    /**
     * Build default request headers for all HubSpot requests.
     *
     * @param string|null $accessToken
     *
     * @return array<string, string>
     */
    private function buildDefaultHeaders(?string $accessToken): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($accessToken !== null && $accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        return $headers;
    }

    /**
     * Validate that the response status is a successful 2xx value.
     *
     * @param ResponseInterface $response
     *
     * @throws HubspotApiException
     */
    private function ensureSuccessfulResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        $body = (string)$response->getBody();

        $correlationId = $this->extractCorrelationId(response: $response, responseBody: $body);

        throw new HubspotApiException(
            message: sprintf('HubSpot API request failed with HTTP status %d.', $statusCode),
            statusCode: $statusCode,
            responseBody: $body,
            correlationId: $correlationId
        );
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $requestOptions
     *
     * @throws HubspotApiException
     */
    private function sendRequestWithRetries(string $method, string $path, array $requestOptions): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->request(
                    method: $method,
                    uri: $path,
                    options: $requestOptions
                );
            } catch (\Throwable $exception) {
                if ($attempt >= self::MAX_RETRY_ATTEMPTS) {
                    throw new HubspotApiException(
                        message: 'HubSpot API transport request failed after retries: ' . $exception->getMessage(),
                        previous: $exception
                    );
                }

                $this->sleepForRetry(attempt: $attempt, retryAfterSeconds: null);
                $attempt++;
                continue;
            }

            if (!$this->shouldRetryStatus($response->getStatusCode()) || $attempt >= self::MAX_RETRY_ATTEMPTS) {
                return $response;
            }

            $this->sleepForRetry(
                attempt: $attempt,
                retryAfterSeconds: $this->extractRetryAfterSeconds($response)
            );
            $attempt++;
        }
    }

    /**
     * Determine whether an HTTP status should trigger retry logic.
     *
     * @param int $statusCode
     *
     * @return bool
     */
    private function shouldRetryStatus(int $statusCode): bool
    {
        return $statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599);
    }

    /**
     * Sleep using Retry-After when available, else exponential backoff.
     *
     * @param int $attempt
     * @param int|null $retryAfterSeconds
     *
     * @return void
     */
    private function sleepForRetry(int $attempt, ?int $retryAfterSeconds): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            usleep($retryAfterSeconds * 1000000);
            return;
        }

        $delayMs = self::BASE_RETRY_DELAY_MS * (2 ** $attempt);
        usleep($delayMs * 1000);
    }

    /**
     * Parse Retry-After header value into seconds.
     *
     * @param ResponseInterface $response
     *
     * @return int|null
     */
    private function extractRetryAfterSeconds(ResponseInterface $response): ?int
    {
        if (!$response->hasHeader('Retry-After')) {
            return null;
        }

        $retryAfterValue = $response->getHeaderLine('Retry-After');
        if ($retryAfterValue === '') {
            return null;
        }

        if (is_numeric($retryAfterValue)) {
            return (int)$retryAfterValue;
        }

        $retryAfterTimestamp = strtotime($retryAfterValue);
        if ($retryAfterTimestamp === false) {
            return null;
        }

        $delta = $retryAfterTimestamp - time();
        return $delta > 0 ? $delta : 0;
    }

    /**
     * Extract HubSpot correlation ID from header or response body.
     *
     * @param ResponseInterface $response
     * @param string $responseBody
     *
     * @return string|null
     */
    private function extractCorrelationId(ResponseInterface $response, string $responseBody): ?string
    {
        $headerCorrelationId = $response->getHeaderLine('x-hubspot-correlation-id');
        if ($headerCorrelationId !== '') {
            return $headerCorrelationId;
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $correlationId = $decoded['correlationId'] ?? null;
        return is_string($correlationId) && $correlationId !== '' ? $correlationId : null;
    }
}
