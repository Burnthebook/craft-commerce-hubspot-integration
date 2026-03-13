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
 * Handles HubSpot contact/company synchronization for orders.
 */
final class HubspotContactsCompaniesHandler
{
    /**
     * Create the contact/company synchronization handler.
     *
     * @param HubspotApiClient $client
     */
    public function __construct(
        private readonly HubspotApiClient $client
    ) {
    }

    /**
     * Sync the order booker and delegates with contacts/companies.
     *
     * @param Order $order
     *
     * @return array{
     *   bookerContactId: string,
     *   bookerCompanyId: string|null,
     *   delegates: array<int, array{contactId: string, companyId: string|null, lineItemId: string|null, email: string}>
     * }
     */
    public function syncOrderContactsAndCompanies(Order $order): array
    {
        /** @var array<string, mixed> $orderData */
        $orderData = $order->toArray();

        $customer = ArrayHelper::getValue($orderData, 'customer', []);
        $billingAddress = ArrayHelper::getValue($orderData, 'billingAddress', []);

        if (!is_array($customer)) {
            $customer = [];
        }

        if (!is_array($billingAddress)) {
            $billingAddress = [];
        }

        $bookerContactId = $this->upsertBookerContact($customer);
        $bookerCompanyId = $this->upsertBookerCompany($customer, $billingAddress);

        if ($bookerCompanyId !== null) {
            $this->client->associateObjectsByType(
                fromObjectType: HubspotObjectType::Contacts,
                fromObjectId: $bookerContactId,
                toObjectType: HubspotObjectType::Companies,
                toObjectId: $bookerCompanyId,
                associationTypeId: HubspotAssociationType::ContactToCompanyPrimary->id(),
                associationCategory: HubspotAssociationCategory::HubspotDefined
            );
        }

        $delegates = $this->syncOrderDelegatesAndCompanies($this->extractDelegateEntries($orderData));

        Craft::info(
            sprintf('Synced contacts/companies for order %s. Delegates: %d', (string)$order->id, count($delegates)),
            'craft-commerce-hubspot-integration'
        );

        return [
            'bookerContactId' => $bookerContactId,
            'bookerCompanyId' => $bookerCompanyId,
            'delegates' => $delegates,
        ];
    }

    /**
     * Upsert the booker contact using email as the dedupe key.
     *
     * @param array<string, mixed> $customer
     *
     * @return string
     */
    public function upsertBookerContact(array $customer): string
    {
        $email = $this->normalizeValue(ArrayHelper::getValue($customer, 'email'));
        if ($email === null) {
            throw new \InvalidArgumentException('Booker email is required for HubSpot contact upsert.');
        }

        /** @var array<string, scalar|null> $incomingProperties */
        $incomingProperties = [
            'email' => $email,
            'firstname' => $this->normalizeValue(ArrayHelper::getValue($customer, 'firstName')),
            'lastname' => $this->normalizeValue(ArrayHelper::getValue($customer, 'lastName')),
            'company' => $this->normalizeValue(ArrayHelper::getValue($customer, 'customerCompany')),
            'jobtitle' => $this->normalizeValue(ArrayHelper::getValue($customer, 'customerJobTitle')),
            'phone' => $this->normalizeValue(ArrayHelper::getValue($customer, 'customerTelephone')),
            'mobilephone' => $this->normalizeValue(ArrayHelper::getValue($customer, 'customerMobile')),
            'craft_contact_id' => $this->normalizeValue(ArrayHelper::getValue($customer, 'id')),
            'dx_number' => $this->normalizeValue(ArrayHelper::getValue($customer, 'customerDxNumber')),
            'dx_exchange' => $this->normalizeValue(ArrayHelper::getValue($customer, 'customerDxExchange')),
            'acquisition_method' => $this->normalizeValue(ArrayHelper::getValue($customer, 'acquisitionMethod.value')),
            'lifecyclestage' => 'customer',
        ];

        $existing = $this->findObjectByProperty(HubspotObjectType::Contacts, 'email', $email, array_keys($incomingProperties));

        if ($existing === null) {
            $created = $this->client->createObject(HubspotObjectType::Contacts, $incomingProperties);
            return (string)($created['id'] ?? '');
        }

        $existingId = (string)($existing['id'] ?? '');
        /** @var array<string, mixed> $existingProperties */
        $existingProperties = is_array($existing['properties'] ?? null) ? $existing['properties'] : [];

        $propertiesToUpdate = $this->buildBlankFillUpdatePayload($existingProperties, $incomingProperties, ['lifecyclestage']);
        if ($propertiesToUpdate !== []) {
            $this->client->updateObject(HubspotObjectType::Contacts, $existingId, $propertiesToUpdate);
        }

        return $existingId;
    }

    /**
     * Upsert the booker's company from customer and billing data.
     *
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $billingAddress
     *
     * @return string|null
     */
    public function upsertBookerCompany(array $customer, array $billingAddress): ?string
    {
        $companyName = $this->normalizeValue(ArrayHelper::getValue($customer, 'customerCompany'));
        if ($companyName === null) {
            return null;
        }

        $craftCompanyId = $this->normalizeValue(ArrayHelper::getValue($customer, 'companyId'));
        $existing = null;

        if ($craftCompanyId !== null) {
            $existing = $this->findObjectByProperty(HubspotObjectType::Companies, 'craft_company_id', $craftCompanyId, ['name', 'craft_company_id']);
        }

        if ($existing === null) {
            $existing = $this->findObjectByProperty(HubspotObjectType::Companies, 'name', $companyName, ['name', 'craft_company_id']);
        }

        /** @var array<string, scalar|null> $companyProperties */
        $companyProperties = [
            'name' => $companyName,
            'craft_company_id' => $craftCompanyId,
            'address' => $this->normalizeValue(ArrayHelper::getValue($billingAddress, 'address1')),
            'city' => $this->normalizeValue(ArrayHelper::getValue($billingAddress, 'city')),
            'zip' => $this->normalizeValue(ArrayHelper::getValue($billingAddress, 'zipCode')),
            'country' => $this->normalizeValue(ArrayHelper::getValue($billingAddress, 'countryText')),
            'phone' => $this->normalizeValue(ArrayHelper::getValue($billingAddress, 'phone')),
        ];

        if ($existing === null) {
            $created = $this->client->createObject(HubspotObjectType::Companies, $companyProperties);
            return (string)($created['id'] ?? '');
        }

        return (string)($existing['id'] ?? '');
    }

    /**
     * Upsert all delegates and their companies for an order.
     *
     * @param array<int, array{lineItemId: string|null, delegate: array<string, mixed>}> $delegateEntries
     *
     * @return array<int, array{contactId: string, companyId: string|null, lineItemId: string|null, email: string}>
     */
    public function syncOrderDelegatesAndCompanies(array $delegateEntries): array
    {
        $syncedDelegates = [];

        foreach ($delegateEntries as $entry) {
            $delegate = $entry['delegate'];
            $email = $this->normalizeValue(ArrayHelper::getValue($delegate, 'email'));

            if ($email === null) {
                continue;
            }

            $contactId = $this->upsertDelegateContact($delegate);
            if ($contactId === null) {
                continue;
            }

            $companyId = $this->upsertDelegateCompany($delegate);
            if ($companyId !== null) {
                $this->client->associateObjectsByType(
                    fromObjectType: HubspotObjectType::Contacts,
                    fromObjectId: $contactId,
                    toObjectType: HubspotObjectType::Companies,
                    toObjectId: $companyId,
                    associationTypeId: HubspotAssociationType::ContactToCompanyPrimary->id(),
                    associationCategory: HubspotAssociationCategory::HubspotDefined
                );
            }

            $syncedDelegates[] = [
                'contactId' => $contactId,
                'companyId' => $companyId,
                'lineItemId' => $entry['lineItemId'],
                'email' => $email,
            ];
        }

        return $syncedDelegates;
    }

    /**
     * Upsert a delegate contact by email.
     *
     * @param array<string, mixed> $delegate
     *
     * @return string|null
     */
    public function upsertDelegateContact(array $delegate): ?string
    {
        $email = $this->normalizeValue(ArrayHelper::getValue($delegate, 'email'));
        if ($email === null) {
            return null;
        }

        /** @var array<string, scalar|null> $incomingProperties */
        $incomingProperties = [
            'email' => $email,
            'firstname' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'firstName')),
            'lastname' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'lastName')),
            'company' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'company')),
            'jobtitle' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'jobTitle')),
            'phone' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'telephone')),
            'mobilephone' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'mobile')),
            'delegate_license' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'license')),
            'gdc_number' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'gdcNumber')),
            'dental_practice_type' => $this->normalizeValue(ArrayHelper::getValue($delegate, 'dentalPracticeType')),
        ];

        $existing = $this->findObjectByProperty(HubspotObjectType::Contacts, 'email', $email, array_keys($incomingProperties));

        if ($existing === null) {
            $created = $this->client->createObject(HubspotObjectType::Contacts, $incomingProperties);
            return (string)($created['id'] ?? '');
        }

        $existingId = (string)($existing['id'] ?? '');
        /** @var array<string, mixed> $existingProperties */
        $existingProperties = is_array($existing['properties'] ?? null) ? $existing['properties'] : [];

        $propertiesToUpdate = $this->buildBlankFillUpdatePayload($existingProperties, $incomingProperties, ['delegate_license']);
        if ($propertiesToUpdate !== []) {
            $this->client->updateObject(HubspotObjectType::Contacts, $existingId, $propertiesToUpdate);
        }

        return $existingId;
    }

    /**
     * Upsert a delegate company by name.
     *
     * @param array<string, mixed> $delegate
     *
     * @return string|null
     */
    public function upsertDelegateCompany(array $delegate): ?string
    {
        $companyName = $this->normalizeValue(ArrayHelper::getValue($delegate, 'company'));
        if ($companyName === null) {
            return null;
        }

        $existing = $this->findObjectByProperty(HubspotObjectType::Companies, 'name', $companyName, ['name']);
        if ($existing !== null) {
            return (string)($existing['id'] ?? '');
        }

        $created = $this->client->createObject(HubspotObjectType::Companies, ['name' => $companyName]);
        return (string)($created['id'] ?? '');
    }

    /**
     * Extract delegates and parent line-item IDs from order data.
     *
     * @param array<string, mixed> $orderData
     *
     * @return array<int, array{lineItemId: string|null, delegate: array<string, mixed>}>
     */
    public function extractDelegateEntries(array $orderData): array
    {
        $itemDelegates = ArrayHelper::getValue($orderData, 'delegates.itemDelegates', []);
        if (!is_array($itemDelegates)) {
            return [];
        }

        $entries = [];

        foreach ($itemDelegates as $itemDelegate) {
            if (!is_array($itemDelegate)) {
                continue;
            }

            $lineItemId = $this->normalizeValue(ArrayHelper::getValue($itemDelegate, 'lineItemId'));
            $delegates = ArrayHelper::getValue($itemDelegate, 'delegates', []);
            if (!is_array($delegates)) {
                continue;
            }

            foreach ($delegates as $delegate) {
                if (!is_array($delegate)) {
                    continue;
                }

                $entries[] = [
                    'lineItemId' => $lineItemId,
                    'delegate' => $delegate,
                ];
            }
        }

        return $entries;
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
     * @param array<int, string> $alwaysOverwriteProperties
     *
     * @return array<string, scalar|null>
     */
    private function buildBlankFillUpdatePayload(array $existingProperties, array $incomingProperties, array $alwaysOverwriteProperties): array
    {
        $updateProperties = [];

        foreach ($incomingProperties as $propertyName => $incomingValue) {
            if ($incomingValue === null || $incomingValue === '') {
                continue;
            }

            if (in_array($propertyName, $alwaysOverwriteProperties, true)) {
                $updateProperties[$propertyName] = $incomingValue;
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
}
