<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\services\handlers;

use Craft;
use Throwable;
use DateTimeImmutable;
use DateTimeInterface;
use yii\helpers\ArrayHelper;
use craft\commerce\elements\Order;
use burnthebook\craftcommercehubspotintegration\enums\HubspotObjectType;
use burnthebook\craftcommercehubspotintegration\services\HubspotApiClient;

/**
 * Handles HubSpot order-object synchronization.
 */
final class HubspotOrderHandler
{
    /**
     * Create the order synchronization handler.
     *
     * @param HubspotApiClient $client
     * @param string $orderPipelineId
     * @param string $orderStageOpenId
     * @param string $orderStageProcessedId
     * @param string $orderStageShippedId
     * @param string $orderStageDeliveredId
     * @param string $orderStageCancelledId
     * @param string $orderSourceStore
     */
    public function __construct(
        private readonly HubspotApiClient $client,
        private readonly string $orderPipelineId,
        private readonly string $orderStageOpenId,
        private readonly string $orderStageProcessedId,
        private readonly string $orderStageShippedId,
        private readonly string $orderStageDeliveredId,
        private readonly string $orderStageCancelledId,
        private readonly string $orderSourceStore
    ) {
    }

    /**
     * Upsert order object in HubSpot using hs_external_order_id.
     *
     * @param Order $order
     */
    public function syncOrderRecord(Order $order): string
    {
        /** @var array<string, mixed> $orderData */
        $orderData = $order->toArray();
        $externalOrderId = (string)$order->id;

        $existing = $this->findObjectByProperty(HubspotObjectType::Order, 'hs_external_order_id', $externalOrderId, ['hs_external_order_id']);
        $properties = $this->buildOrderProperties($orderData);

        if ($existing === null) {
            $created = $this->client->createObject(HubspotObjectType::Order, $properties);
            $hubspotOrderId = (string)($created['id'] ?? '');

            Craft::info(
                sprintf('Created HubSpot order %s for Craft order %s.', $hubspotOrderId, $externalOrderId),
                'craft-commerce-hubspot-integration'
            );

            return $hubspotOrderId;
        }

        $hubspotOrderId = (string)($existing['id'] ?? '');
        $this->client->updateObject(HubspotObjectType::Order, $hubspotOrderId, $properties);

        Craft::info(
            sprintf('Updated HubSpot order %s for Craft order %s.', $hubspotOrderId, $externalOrderId),
            'craft-commerce-hubspot-integration'
        );

        return $hubspotOrderId;
    }

    /**
     * @param array<string, mixed> $orderData
     *
     * @return array<string, scalar|null>
     */
    private function buildOrderProperties(array $orderData): array
    {
        $orderId = $this->normalizeValue(ArrayHelper::getValue($orderData, 'id'));
        $billing = ArrayHelper::getValue($orderData, 'billingAddress', []);
        $customer = ArrayHelper::getValue($orderData, 'customer', []);

        if (!is_array($billing)) {
            $billing = [];
        }

        if (!is_array($customer)) {
            $customer = [];
        }

        return [
            'hs_external_order_id' => $orderId,
            'hs_order_name' => $orderId !== null ? 'CraftCMS Order #' . $orderId : null,
            'hs_external_order_status' => $this->normalizeValue(ArrayHelper::getValue($orderData, 'status')),
            'hs_external_created_date' => $this->normalizeDateTimeValue(ArrayHelper::getValue($orderData, 'dateOrdered')),
            'date_paid' => $this->normalizeDateTimeValue(ArrayHelper::getValue($orderData, 'datePaid')),
            'hs_currency_code' => $this->normalizeValue(ArrayHelper::getValue($orderData, 'currency')),
            'hs_subtotal_price' => $this->normalizeNumericValue(ArrayHelper::getValue($orderData, 'itemSubtotal')),
            'hs_total_price' => $this->normalizeNumericValue(ArrayHelper::getValue($orderData, 'totalPrice')),
            'hs_tax' => $this->normalizeNumericValue(ArrayHelper::getValue($orderData, 'adjustmentsTotal')),
            'total_paid' => $this->normalizeNumericValue(ArrayHelper::getValue($orderData, 'totalPaid')),
            'outstanding_balance' => $this->normalizeNumericValue(ArrayHelper::getValue($orderData, 'outstandingBalance')),
            'total_quantity' => $this->normalizeNumericValue(ArrayHelper::getValue($orderData, 'totalQty')),
            'hs_discount_codes' => $this->normalizeValue(ArrayHelper::getValue($orderData, 'couponCode')),
            'craft_customer_id' => $this->normalizeValue(ArrayHelper::getValue($customer, 'id')),
            'course_summary' => $this->buildCourseSummary($orderData),
            'delegate_summary' => $this->buildDelegateSummary($orderData),
            'hs_billing_address_firstname' => $this->normalizeValue(ArrayHelper::getValue($billing, 'firstName')),
            'hs_billing_address_lastname' => $this->normalizeValue(ArrayHelper::getValue($billing, 'lastName')),
            'hs_billing_address_name' => $this->normalizeValue(ArrayHelper::getValue($billing, 'businessName')),
            'hs_billing_address_street' => $this->concatenateStreet(
                $this->normalizeValue(ArrayHelper::getValue($billing, 'address1')),
                $this->normalizeValue(ArrayHelper::getValue($billing, 'address2'))
            ),
            'hs_billing_address_city' => $this->normalizeValue(ArrayHelper::getValue($billing, 'city')),
            'hs_billing_address_postal_code' => $this->normalizeValue(ArrayHelper::getValue($billing, 'zipCode')),
            'hs_billing_address_country' => $this->normalizeValue(ArrayHelper::getValue($billing, 'countryText')),
            'hs_billing_address_phone' => $this->normalizeValue(ArrayHelper::getValue($billing, 'phone')),
            'hs_billing_address_email' => $this->normalizeValue(ArrayHelper::getValue($billing, 'email')),
            'hs_source_store' => $this->orderSourceStore,
            'hs_pipeline' => $this->orderPipelineId,
            'hs_pipeline_stage' => $this->resolveOrderStageId(
                $this->normalizeValue(ArrayHelper::getValue($orderData, 'status'))
            ),
        ];
    }

    /**
     * Resolve HubSpot order stage ID from Craft order status.
     *
     * @param string|null $status
     *
     * @return string
     */
    private function resolveOrderStageId(?string $status): string
    {
        return match (strtolower(trim((string)$status))) {
            'processed' => $this->orderStageProcessedId,
            'shipped' => $this->orderStageShippedId,
            'delivered' => $this->orderStageDeliveredId,
            'cancelled', 'canceled' => $this->orderStageCancelledId,
            default => $this->orderStageOpenId,
        };
    }

    /**
     * Build a semicolon-delimited course summary string.
     *
     * @param array<string, mixed> $orderData
     *
     * @return string|null
     */
    private function buildCourseSummary(array $orderData): ?string
    {
        $lineItems = ArrayHelper::getValue($orderData, 'lineItems', []);
        if (!is_array($lineItems)) {
            return null;
        }

        $descriptions = [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $description = $this->normalizeValue(ArrayHelper::getValue($lineItem, 'description'));
            if ($description !== null) {
                $descriptions[] = $description;
            }
        }

        return $descriptions !== [] ? implode('; ', $descriptions) : null;
    }

    /**
     * Build a semicolon-delimited delegate summary string.
     *
     * @param array<string, mixed> $orderData
     *
     * @return string|null
     */
    private function buildDelegateSummary(array $orderData): ?string
    {
        $itemDelegates = ArrayHelper::getValue($orderData, 'delegates.itemDelegates', []);
        if (!is_array($itemDelegates)) {
            return null;
        }

        $names = [];
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

                $firstName = $this->normalizeValue(ArrayHelper::getValue($delegate, 'firstName'));
                $lastName = $this->normalizeValue(ArrayHelper::getValue($delegate, 'lastName'));
                $name = trim(implode(' ', array_filter([$firstName, $lastName])));

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names !== [] ? implode('; ', $names) : null;
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
     * Join address lines into a single street field.
     *
     * @param string|null $address1
     * @param string|null $address2
     *
     * @return string|null
     */
    private function concatenateStreet(?string $address1, ?string $address2): ?string
    {
        $parts = array_filter([$address1, $address2], static fn (?string $part): bool => $part !== null && $part !== '');
        return $parts !== [] ? implode(', ', $parts) : null;
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
    private function normalizeDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (!is_scalar($value)) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalize mixed numeric input for HubSpot number fields.
     *
     * @param mixed $value
     *
     * @return int|float|null
     */
    private function normalizeNumericValue(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !is_numeric($trimmed)) {
                return null;
            }

            return str_contains($trimmed, '.') ? (float)$trimmed : (int)$trimmed;
        }

        return null;
    }
}
