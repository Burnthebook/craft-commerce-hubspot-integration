<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\services\handlers;

use Craft;
use yii\helpers\ArrayHelper;
use craft\commerce\elements\Order;
use burnthebook\craftcommercehubspotintegration\enums\HubspotObjectType;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiClient;

/**
 * Handles HubSpot course custom-object synchronization.
 */
final class HubspotCourseHandler
{
    /**
     * Create the course synchronization handler.
     *
     * @param HubspotApiClient $client
     * @param string $coursePipelineId
     * @param string $courseStageOpenId
     * @param string $courseStageClosedId
     */
    public function __construct(
        private readonly HubspotApiClient $client,
        private readonly string $coursePipelineId,
        private readonly string $courseStageOpenId,
        private readonly string $courseStageClosedId
    ) {
    }

    /**
     * Sync unique order courses by line item SKU.
     *
     * @param Order $order
     *
     * @return array<string, string>
     */
    public function syncOrderCourses(Order $order): array
    {
        /** @var array<string, mixed> $orderData */
        $orderData = $order->toArray();

        $lineItems = ArrayHelper::getValue($orderData, 'lineItems', []);
        if (!is_array($lineItems)) {
            return [];
        }

        /** @var array<string, string> $courseMap */
        $courseMap = [];

        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $sku = $this->normalizeValue(ArrayHelper::getValue($lineItem, 'sku'));
            if ($sku === null || isset($courseMap[$sku])) {
                continue;
            }

            $description = $this->normalizeValue(ArrayHelper::getValue($lineItem, 'description'));
            $conferenceStartDate = $this->normalizeDateValue(ArrayHelper::getValue($lineItem, 'ConferenceStartDate'));
            $typeId = $this->normalizeValue(ArrayHelper::getValue($lineItem, 'type_id'));
            $courseMap[$sku] = $this->upsertCourseBySku(
                sku: $sku,
                description: $description,
                status: $this->normalizeValue(ArrayHelper::getValue($orderData, 'status')),
                conferenceStartDate: $conferenceStartDate,
                typeId: $typeId
            );
        }

        Craft::info(
            sprintf('Synced %d courses for order %s.', count($courseMap), (string)$order->id),
            'craft-commerce-hubspot-integration'
        );

        return $courseMap;
    }

    /**
     * Upsert a course custom object keyed by SKU.
     *
     * @param string $sku
     * @param string|null $description
     * @param string|null $status
     * @param string|null $conferenceStartDate
     * @param string|null $typeId
     *
     * @return string
     */
    public function upsertCourseBySku(
        string $sku,
        ?string $description,
        ?string $status = null,
        ?string $conferenceStartDate = null,
        ?string $typeId = null
    ): string
    {
        $existing = $this->findObjectByProperty(
            HubspotObjectType::Course,
            'craft_course_id',
            $sku,
            ['craft_course_id', 'hs_course_name', 'hs_course_id', 'course_date', 'type_id']
        );

        if ($existing !== null) {
            $propertiesToUpdate = $this->buildBlankFillUpdatePayload(
                existingProperties: is_array($existing['properties'] ?? null) ? $existing['properties'] : [],
                incomingProperties: [
                    'course_date' => $conferenceStartDate,
                    'type_id' => $typeId,
                ]
            );

            if ($propertiesToUpdate !== []) {
                $this->client->updateObject(
                    objectType: HubspotObjectType::Course,
                    objectId: (string)($existing['id'] ?? ''),
                    properties: $propertiesToUpdate
                );
            }

            return (string)($existing['id'] ?? '');
        }

        /** @var array<string, scalar|null> $properties */
        $properties = [
            'craft_course_id' => $sku,
            'hs_course_name' => $description,
            'hs_course_id' => $sku,
            'course_date' => $conferenceStartDate,
            'type_id' => $typeId,
            'hs_pipeline' => $this->coursePipelineId,
            'hs_pipeline_stage' => $this->resolveCourseStageId($status),
        ];

        $created = $this->client->createObject(HubspotObjectType::Course, $properties);
        return (string)($created['id'] ?? '');
    }

    /**
     * Resolve the course stage ID from the order status.
     *
     * @param string|null $status
     *
     * @return string
     */
    private function resolveCourseStageId(?string $status): string
    {
        $normalizedStatus = strtolower(trim((string)$status));

        if (in_array($normalizedStatus, ['processed', 'shipped', 'delivered'], true)) {
            return $this->courseStageClosedId;
        }

        return $this->courseStageOpenId;
    }

    /**
     * Search for a single CRM object by property equality.
     *
     * @param string|HubspotObjectType $objectType
     * @param string $propertyName
     * @param string $propertyValue
     * @param array<int, string> $properties
     *
     * @return array<string, mixed>|null
     */
    private function findObjectByProperty(string|HubspotObjectType $objectType, string $propertyName, string $propertyValue, array $properties): ?array
    {
        $result = $this->client->searchObjects(
            objectType: $objectType,
            filterGroups: [[
                'filters' => [[
                    'propertyName' => $propertyName,
                    'operator' => 'EQ',
                    'value' => $propertyValue,
                ]],
            ]],
            properties: $properties,
            limit: 1
        );

        $results = is_array($result['results'] ?? null) ? $result['results'] : [];
        if ($results === []) {
            return null;
        }

        $first = $results[0] ?? null;
        return is_array($first) ? $first : null;
    }

    /**
     * Build a patch payload that only fills blank existing values.
     *
     * @param array<string, mixed> $existingProperties
     * @param array<string, scalar|null> $incomingProperties
     *
     * @return array<string, scalar|null>
     */
    private function buildBlankFillUpdatePayload(array $existingProperties, array $incomingProperties): array
    {
        $updateProperties = [];

        foreach ($incomingProperties as $propertyName => $incomingValue) {
            if ($incomingValue === null || $incomingValue === '') {
                continue;
            }

            $existingValue = $existingProperties[$propertyName] ?? null;
            if ($existingValue === null || $existingValue === '') {
                $updateProperties[$propertyName] = $incomingValue;
            }
        }

        return $updateProperties;
    }

    /**
     * Normalize mixed scalar input into a trimmed nullable string.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function normalizeValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string)$value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Normalize mixed datetime input to ISO 8601.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function normalizeDateValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
