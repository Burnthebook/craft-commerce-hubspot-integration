<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\services;

use craft\commerce\elements\Order;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotOrderHandler;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotCourseHandler;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotAssociationHandler;
use burnthebook\craftcommercehubspotintegration\services\handlers\HubspotContactsCompaniesHandler;

/**
 * Orchestration layer for HubSpot sync handlers.
 */
final class HubspotApiService
{
    /**
     * Create the orchestration service with all stage handlers.
     *
     * @param HubspotContactsCompaniesHandler $contactsCompaniesHandler
     * @param HubspotCourseHandler $courseHandler
     * @param HubspotOrderHandler $orderHandler
     * @param HubspotAssociationHandler $associationHandler
     */
    public function __construct(
        private readonly HubspotContactsCompaniesHandler $contactsCompaniesHandler,
        private readonly HubspotCourseHandler $courseHandler,
        private readonly HubspotOrderHandler $orderHandler,
        private readonly HubspotAssociationHandler $associationHandler
    ) {
    }

    /**
     * Sync contact/company records for the order and delegates.
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
        return $this->contactsCompaniesHandler->syncOrderContactsAndCompanies($order);
    }

    /**
     * Sync all unique courses associated with the order.
     *
     * @param Order $order
     *
     * @return array<string, string>
     */
    public function syncOrderCourses(Order $order): array
    {
        return $this->courseHandler->syncOrderCourses($order);
    }

    /**
     * Sync the HubSpot order object.
     *
     * @param Order $order
     *
     * @return string
     */
    public function syncOrderRecord(Order $order): string
    {
        return $this->orderHandler->syncOrderRecord($order);
    }

    /**
     * Sync cross-object associations after record upserts.
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
        $this->associationHandler->syncOrderAssociations(
            order: $order,
            orderHubspotId: $orderHubspotId,
            bookerContactId: $bookerContactId,
            bookerCompanyId: $bookerCompanyId,
            delegates: $delegates,
            courseMap: $courseMap
        );
    }
}
