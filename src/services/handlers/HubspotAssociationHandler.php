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
        $delegateEmailMap = $this->buildDelegateEmailMap($order);
        $bookerEmail = $this->getBookerEmail($order);
        $bookerIsDelegate = $bookerEmail !== null && isset($delegateEmailMap[$bookerEmail]);

        $bookerOrderAssociations = [[
            'associationCategory' => HubspotAssociationCategory::UserDefined->value,
            'associationTypeId' => HubspotAssociationType::ContactToOrderBooker->id(),
        ]];

        if ($bookerIsDelegate) {
            $bookerOrderAssociations[] = [
                'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                'associationTypeId' => HubspotAssociationType::ContactToOrderDelegate->id(),
            ];
        }

        $this->client->associateObjects(
            fromObjectType: HubspotObjectType::Contacts,
            fromObjectId: $bookerContactId,
            toObjectType: 'orders',
            toObjectId: $orderHubspotId,
            associationTypes: $bookerOrderAssociations
        );

        Craft::info(
            sprintf(
                'Associated booker contact %s to order %s with labels %s.',
                $bookerContactId,
                $orderHubspotId,
                implode(',', array_map(
                    static fn(array $label): string => (string)$label['associationTypeId'],
                    $bookerOrderAssociations
                ))
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
            $bookerCourseAssociations = [[
                'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                'associationTypeId' => HubspotAssociationType::ContactToCourseBooker->id(),
            ]];

            if ($bookerIsDelegate) {
                $bookerCourseAssociations[] = [
                    'associationCategory' => HubspotAssociationCategory::UserDefined->value,
                    'associationTypeId' => HubspotAssociationType::ContactToCourseDelegate->id(),
                ];
            }

            $this->client->associateObjects(
                fromObjectType: HubspotObjectType::Contacts,
                fromObjectId: $bookerContactId,
                toObjectType: HubspotObjectType::Course,
                toObjectId: $courseId,
                associationTypes: $bookerCourseAssociations
            );

            Craft::info(
                sprintf(
                    'Associated booker contact %s to course %s with labels %s.',
                    $bookerContactId,
                    $courseId,
                    implode(',', array_map(
                        static fn(array $label): string => (string)$label['associationTypeId'],
                        $bookerCourseAssociations
                    ))
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

    /**
     * Build a map of delegate emails for quick lookups.
     *
     * @param Order $order
     *
     * @return array<string, true>
     */
    private function buildDelegateEmailMap(Order $order): array
    {
        /** @var array<string, mixed> $orderData */
        $orderData = $order->toArray();
        $itemDelegates = ArrayHelper::getValue($orderData, 'delegates.itemDelegates', []);

        if (!is_array($itemDelegates)) {
            return [];
        }

        $emailMap = [];

        foreach ($itemDelegates as $itemDelegate) {
            if (!is_array($itemDelegate)) {
                continue;
            }

            $delegates = ArrayHelper::getValue($itemDelegate, 'delegates', []);
            if (!is_array($delegates)) {
                continue;
            }

            foreach ($delegates as $delegate) {
                if (!is_array($delegate)) {
                    continue;
                }

                $email = $this->normalizeValue(ArrayHelper::getValue($delegate, 'email'));
                if ($email !== null) {
                    $emailMap[strtolower($email)] = true;
                }
            }
        }

        return $emailMap;
    }

    /**
     * Resolve the booker email from the order payload.
     *
     * @param Order $order
     *
     * @return string|null
     */
    private function getBookerEmail(Order $order): ?string
    {
        /** @var array<string, mixed> $orderData */
        $orderData = $order->toArray();

        $email = $this->normalizeValue(ArrayHelper::getValue($orderData, 'customer.email'));
        return $email !== null ? strtolower($email) : null;
    }
}
