<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Craft;
use Throwable;
use yii\queue\Queue;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;

/**
 * Stage 1: sync contacts and companies.
 */
final class HubspotContactsCompaniesSyncJob extends AbstractHubspotSyncStageJob
{
    /**
     * Execute the contacts/companies stage and queue the courses stage.
     *
     * @param Queue $queue
     *
     * @return void
     */
    public function execute($queue): void
    {
        try {
            $order = $this->requireOrder();

            /**
             * @var array{
             *   bookerContactId: string,
             *   bookerCompanyId: string|null,
             *   delegates: array<int, array{contactId: string, companyId: string|null, lineItemId: string|null, email: string}>
             * } $contactsAndCompanies
             */
            $contactsAndCompanies = CommerceHubspotIntegration::getInstance()
                ->getHubspotApiService()
                ->syncOrderContactsAndCompanies($order);

            Craft::$app->getQueue()->push(new HubspotCoursesSyncJob([
                'orderId' => $this->orderId,
                'payloadHash' => $this->payloadHash,
                'contactsAndCompanies' => $contactsAndCompanies,
            ]));
        } catch (Throwable $exception) {
            $this->markFailed($exception);
            throw $exception;
        }
    }

    /**
     * Return a human-readable queue job description.
     *
     * @return string
     */
    protected function defaultDescription(): string
    {
        return 'Sync HubSpot contacts/companies for order #' . $this->orderId;
    }
}
