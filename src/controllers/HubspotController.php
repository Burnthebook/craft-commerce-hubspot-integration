<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\controllers;

use Craft;
use yii\web\Response;
use craft\web\Controller;
use burnthebook\craftcommercehubspotintegration\models\Settings;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiClient;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;
use burnthebook\craftcommercehubspotintegration\exceptions\HubspotApiException;

/**
 * HubSpot controller actions for plugin admin tools.
 */
final class HubspotController extends Controller
{
    /**
     * @var bool|int|array<int, string>
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Test HubSpot API connectivity from plugin settings.
     *
     * Supports both JSON and CP redirect response modes.
     *
     * @return Response
     */
    public function actionTestConnection(): Response
    {
        $request = Craft::$app->getRequest();
        $isJsonRequest = $request->acceptsJson || $request->getIsAjax();

        $baseUri = trim((string)$request->getBodyParam('hubspotApiBaseUrl', ''));
        $token = trim((string)$request->getBodyParam('hubspotPrivateAppToken', ''));

        if ($baseUri === '' || $token === '') {
            $plugin = CommerceHubspotIntegration::getInstance();
            if ($plugin instanceof CommerceHubspotIntegration) {
                /** @var Settings $settings */
                $settings = $plugin->getSettings();
                $baseUri = $settings->getParsedHubspotApiBaseUrl();
                $token = $settings->getParsedHubspotPrivateAppToken();
            }
        }

        if ($baseUri === '' || $token === '') {
            return $this->failureResponse(
                message: 'HubSpot API base URL and private app token are required.',
                isJsonRequest: $isJsonRequest,
                correlationId: null,
                statusCode: null,
                responseBody: null
            );
        }

        $client = new HubspotApiClient(baseUri: $baseUri, accessToken: $token);

        try {
            $response = $client->get(
                path: '/crm/v3/objects/contacts',
                requestOptions: [
                    'query' => [
                        'limit' => 1,
                        'properties' => ['email'],
                    ],
                ]
            );
        } catch (HubspotApiException $exception) {
            $hubspotMessage = $this->extractHubspotErrorMessage($exception->getResponseBody());
            $statusCode = $exception->getStatusCode();

            $message = 'HubSpot connection test failed';
            if ($statusCode > 0) {
                $message .= ' (HTTP ' . $statusCode . ')';
            }

            if ($hubspotMessage !== null) {
                $message .= ': ' . $hubspotMessage;
            } else {
                $message .= '.';
            }

            return $this->failureResponse(
                message: $message,
                isJsonRequest: $isJsonRequest,
                correlationId: $exception->getCorrelationId(),
                statusCode: $exception->getStatusCode(),
                responseBody: $exception->getResponseBody()
            );
        }

        $message = 'Successfully connected to HubSpot.';

        if ($isJsonRequest) {
            return $this->asSuccess(
                message: $message,
                data: [
                    'message' => $message,
                    'resultCount' => is_array($response['results'] ?? null) ? count($response['results']) : 0,
                    'correlationId' => null,
                ]
            );
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirect($request->getReferrer() ?: 'settings/plugins/craft-commerce-hubspot-integration');
    }

    /**
     * Build a failed test response for JSON or CP redirect flows.
     *
     * @param string $message
     * @param bool $isJsonRequest
     * @param string|null $correlationId
     * @param int|null $statusCode
     * @param string|null $responseBody
     *
     * @return Response
     */
    private function failureResponse(
        string $message,
        bool $isJsonRequest,
        ?string $correlationId,
        ?int $statusCode,
        ?string $responseBody
    ): Response {
        if ($isJsonRequest) {
            return $this->asFailure(
                message: $message,
                data: [
                    'statusCode' => $statusCode,
                    'correlationId' => $correlationId,
                    'responseBody' => $responseBody,
                ]
            );
        }

        $suffix = $correlationId !== null && $correlationId !== ''
            ? ' (correlation: ' . $correlationId . ')'
            : '';

        Craft::$app->getSession()->setError($message . $suffix);

        $request = Craft::$app->getRequest();

        return $this->redirect($request->getReferrer() ?: 'settings/plugins/craft-commerce-hubspot-integration');
    }

    /**
     * Extract a human-readable message from a HubSpot error response body.
     *
     * @param string $responseBody
     *
     * @return string|null
     */
    private function extractHubspotErrorMessage(string $responseBody): ?string
    {
        if ($responseBody === '') {
            return null;
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return null;
        }

        $message = $decoded['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        $category = $decoded['category'] ?? null;
        if (is_string($category) && trim($category) !== '') {
            return trim($category);
        }

        return null;
    }
}
