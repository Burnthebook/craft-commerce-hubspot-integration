<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Craft Commerce HubSpot Integration settings.
 */
final class Settings extends Model
{
    /**
     * HubSpot API base URL.
     */
    public string $hubspotApiBaseUrl = '';

    /**
     * HubSpot private app token.
     */
    public string $hubspotPrivateAppToken = '';

    /**
     * HubSpot Course pipeline ID.
     */
    public string $hubspotCoursePipelineId = '9dd7104c-1ae0-402b-a194-9cc567fd6a45';

    /**
     * HubSpot Course Open stage ID.
     */
    public string $hubspotCourseStageOpenId = '3e1a235d-1a64-4b7a-9ed5-7f0273ebd774';

    /**
     * HubSpot Course Closed stage ID.
     */
    public string $hubspotCourseStageClosedId = '38942bdc-b389-487e-acf3-a43a2772a447';

    /**
     * HubSpot Order pipeline ID.
     */
    public string $hubspotOrderPipelineId = '14a2e10e-5471-408a-906e-c51f3b04369e';

    /**
     * HubSpot Order Open stage ID.
     */
    public string $hubspotOrderStageOpenId = '4b27b500-f031-4927-9811-68a0b525cbae';

    /**
     * HubSpot Order Processed stage ID.
     */
    public string $hubspotOrderStageProcessedId = '937ea84d-0a4f-4dcf-9028-3f9c2aafbf03';

    /**
     * HubSpot Order Shipped stage ID.
     */
    public string $hubspotOrderStageShippedId = 'aa99e8d0-c1d5-4071-b915-d240bbb1aed9';

    /**
     * HubSpot Order Delivered stage ID.
     */
    public string $hubspotOrderStageDeliveredId = '3725360f-519b-4b18-a593-494d60a29c9f';

    /**
     * HubSpot Order Cancelled stage ID.
     */
    public string $hubspotOrderStageCancelledId = '3c85a297-e9ce-400b-b42e-9f16853d69d6';

    /**
     * Source store value for HubSpot orders.
     */
    public string $hubspotOrderSourceStore = 'CraftCMS';

    /**
     * Define validation rules for all required settings.
     *
     * @return array<int, array{0: array<int, string>, 1: string}>
     */
    public function defineRules(): array
    {
        return [
            [
                [
                    'hubspotApiBaseUrl',
                    'hubspotPrivateAppToken',
                    'hubspotCoursePipelineId',
                    'hubspotCourseStageOpenId',
                    'hubspotCourseStageClosedId',
                    'hubspotOrderPipelineId',
                    'hubspotOrderStageOpenId',
                    'hubspotOrderStageProcessedId',
                    'hubspotOrderStageShippedId',
                    'hubspotOrderStageDeliveredId',
                    'hubspotOrderStageCancelledId',
                    'hubspotOrderSourceStore',
                ],
                'required',
            ],
        ];
    }

    /**
     * Returns the configured private app token.
     *
     * @return string
     */
    public function getPrivateAppToken(): string
    {
        return $this->hubspotPrivateAppToken;
    }

    /**
     * Returns parsed HubSpot API base URL.
     */
    public function getParsedHubspotApiBaseUrl(): string
    {
        return trim((string)App::parseEnv($this->hubspotApiBaseUrl));
    }

    /**
     * Returns parsed HubSpot private app token.
     */
    public function getParsedHubspotPrivateAppToken(): string
    {
        return trim((string)App::parseEnv($this->hubspotPrivateAppToken));
    }

    /**
     * Returns parsed Course pipeline ID.
     */
    public function getParsedHubspotCoursePipelineId(): string
    {
        return trim((string)App::parseEnv($this->hubspotCoursePipelineId));
    }

    /**
     * Returns parsed Course Open stage ID.
     */
    public function getParsedHubspotCourseStageOpenId(): string
    {
        return trim((string)App::parseEnv($this->hubspotCourseStageOpenId));
    }

    /**
     * Returns parsed Course Closed stage ID.
     */
    public function getParsedHubspotCourseStageClosedId(): string
    {
        return trim((string)App::parseEnv($this->hubspotCourseStageClosedId));
    }

    /**
     * Returns parsed Order pipeline ID.
     */
    public function getParsedHubspotOrderPipelineId(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderPipelineId));
    }

    /**
     * Returns parsed Order Open stage ID.
     */
    public function getParsedHubspotOrderStageOpenId(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderStageOpenId));
    }

    /**
     * Returns parsed Order Processed stage ID.
     */
    public function getParsedHubspotOrderStageProcessedId(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderStageProcessedId));
    }

    /**
     * Returns parsed Order Shipped stage ID.
     */
    public function getParsedHubspotOrderStageShippedId(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderStageShippedId));
    }

    /**
     * Returns parsed Order Delivered stage ID.
     */
    public function getParsedHubspotOrderStageDeliveredId(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderStageDeliveredId));
    }

    /**
     * Returns parsed Order Cancelled stage ID.
     */
    public function getParsedHubspotOrderStageCancelledId(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderStageCancelledId));
    }

    /**
     * Returns parsed HubSpot order source store value.
     */
    public function getParsedHubspotOrderSourceStore(): string
    {
        return trim((string)App::parseEnv($this->hubspotOrderSourceStore));
    }
}
