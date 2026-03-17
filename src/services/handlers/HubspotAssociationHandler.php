<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\services\handlers;

use Craft;
use yii\helpers\ArrayHelper;
use craft\commerce\elements\Order;
use burnthebook\craftcommercehubspotintegration\enums\HubspotObjectType;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiClient;
use burnthebook\craftcommercehubspotintegration\enums\HubspotAssociationType;
use burnthebook\craftcommercehubspotintegration\enums\HubspotAssociationCategory;

/**
 * Handles HubSpot association synchronization.
 */
final class HubspotAssociationHandler
{
    /**
     * Create the association synchronization handler.
     *
     * @param HubspotApiClient $client
     */
    public function __construct(
        private readonly HubspotApiClient $client
    ) {
    }

    /**
     * Sync order-related associations (spec steps 8-13).
     *
     * @param Order $order
     * @param string $orderHubspotId
     * @param string $bookerContactId
     * @param string|null $bookerCompanyId
     * @param array<int, array{contactId: string, companyId: string|null, lineItemId: string|null, email: string}> $delegates
     * @param array<string, string> $courseMap
     */
    public function syncOrderAssociations(
        Order $order,
        string $orderHubspotId,
        string $bookerContactId,
        ?string $bookerCompanyId,
        array $delegates,
        array $courseMap
    ): void {
        $this->client->associateObjects(
            fromObjectType: HubspotObjectType::Contacts,
            fromObjectId: $bookerContactId,
            toObjectType: 'orders',
            toObjectId: $orderHubspotId,
            associationTypes: [
                [
                    'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                    'associationTypeId' => HubspotAssociationType::ContactToOrderBooker->id(),
                ],
                [
                    'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                    'associationTypeId' => HubspotAssociationType::ContactToOrderDelegate->id(),
                ],
            ]
        );

        Craft::info(
            sprintf(
                'Associated booker contact %s to order %s with labels %d,%d.',
                $bookerContactId,
                $orderHubspotId,
                HubspotAssociationType::ContactToOrderBooker->id(),
                HubspotAssociationType::ContactToOrderDelegate->id()
            ),
            'craft-commerce-hubspot-integration'
        );

        foreach ($delegates as $delegate) {
            if ($delegate['contactId'] === $bookerContactId) {
                continue;
            }

            $this->client->associateObjectsByType(
                fromObjectType: HubspotObjectType::Contacts,
                fromObjectId: $delegate['contactId'],
                toObjectType: 'orders',
                toObjectId: $orderHubspotId,
                associationTypeId: HubspotAssociationType::ContactToOrderDelegate->id(),
                associationCategory: HubspotAssociationCategory::UserDefined
            );
        }

        foreach ($courseMap as $courseId) {
            $this->client->associateObjects(
                fromObjectType: HubspotObjectType::Contacts,
                fromObjectId: $bookerContactId,
                toObjectType: HubspotObjectType::Course,
                toObjectId: $courseId,
                associationTypes: [
                    [
                        'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                        'associationTypeId' => HubspotAssociationType::ContactToCourseBooker->id(),
                    ],
                    [
                        'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                        'associationTypeId' => HubspotAssociationType::ContactToCourseDelegate->id(),
                    ],
                ]
            );

            Craft::info(
                sprintf(
                    'Associated booker contact %s to course %s with labels %d,%d.',
                    $bookerContactId,
                    $courseId,
                    HubspotAssociationType::ContactToCourseBooker->id(),
                    HubspotAssociationType::ContactToCourseDelegate->id()
                ),
                'craft-commerce-hubspot-integration'
            );
        }

        if ($bookerCompanyId !== null) {
            $this->client->associateObjectsByType(
                fromObjectType: HubspotObjectType::Companies,
                fromObjectId: $bookerCompanyId,
                toObjectType: 'orders',
                toObjectId: $orderHubspotId,
                associationTypeId: HubspotAssociationType::CompanyToOrderPrimary->id(),
                associationCategory: HubspotAssociationCategory::HubspotDefined
            );
        }

        $lineItemSkuMap = $this->buildLineItemSkuMap($order);

        foreach ($delegates as $delegate) {
            if ($delegate['contactId'] === $bookerContactId) {
                continue;
            }

            $lineItemId = $delegate['lineItemId'];
            if ($lineItemId === null) {
                continue;
            }

            $sku = $lineItemSkuMap[$lineItemId] ?? null;
            if ($sku === null) {
                continue;
            }

            $courseId = $courseMap[$sku] ?? null;
            if ($courseId === null) {
                continue;
            }

            $this->client->associateObjectsByType(
                fromObjectType: HubspotObjectType::Contacts,
                fromObjectId: $delegate['contactId'],
                toObjectType: HubspotObjectType::Course,
                toObjectId: $courseId,
                associationTypeId: HubspotAssociationType::ContactToCourseDelegate->id(),
                associationCategory: HubspotAssociationCategory::UserDefined
            );

            if ($delegate['companyId'] !== null) {
                $this->client->associateObjectsByType(
                    fromObjectType: HubspotObjectType::Companies,
                    fromObjectId: $delegate['companyId'],
                    toObjectType: HubspotObjectType::Course,
                    toObjectId: $courseId,
                    associationTypeId: HubspotAssociationType::CompanyToCourseRegistrations->id(),
                    associationCategory: HubspotAssociationCategory::UserDefined
                );
            }
        }

        Craft::info(
            sprintf('Synced associations for order %s -> HubSpot order %s.', (string)$order->id, $orderHubspotId),
            'craft-commerce-hubspot-integration'
        );
    }

    /**
     * Build lineItemId=>sku map.
     *
     * @param Order $order
     *
     * @return array<string, string>
     */
    private function buildLineItemSkuMap(Order $order): array
    {
        /** @var array<string, mixed> $orderData */
        $orderData = $order->toArray();

        $lineItems = ArrayHelper::getValue($orderData, 'lineItems', []);
        if (!is_array($lineItems)) {
            return [];
        }

        /** @var array<string, string> $lineItemSkuMap */
        $lineItemSkuMap = [];

        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $sku = $this->normalizeValue(ArrayHelper::getValue($lineItem, 'sku'));
            if ($sku === null) {
                continue;
            }

            $candidateIds = [
                $this->normalizeValue(ArrayHelper::getValue($lineItem, 'lineItemId')),
                $this->normalizeValue(ArrayHelper::getValue($lineItem, 'id')),
            ];

            foreach ($candidateIds as $candidateId) {
                if ($candidateId !== null) {
                    $lineItemSkuMap[$candidateId] = $sku;
                }
            }
        }

        return $lineItemSkuMap;
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
}
