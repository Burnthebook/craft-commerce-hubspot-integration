<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Craft;
use Throwable;
use yii\queue\Queue;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;

/**
 * Stage 3: sync order object.
 */
final class HubspotOrderRecordSyncJob extends AbstractHubspotSyncStageJob
{
    /**
     * @var array{
     *   bookerContactId: string,
     *   bookerCompanyId: string|null,
     *   delegates: array<int, array{contactId: string, companyId: string|null, lineItemId: string|null, email: string}>
     * }
     */
    public array $contactsAndCompanies = [];

    /**
     * @var array<string, string>
     */
    public array $courseMap = [];

    /**
     * Execute the order-record stage and queue the associations stage.
     *
     * @param Queue $queue
     *
     * @return void
     */
    public function execute($queue): void
    {
        try {
            $order = $this->requireOrder();
            $hubspotOrderId = CommerceHubspotIntegration::getInstance()
                ->getHubspotApiService()
                ->syncOrderRecord($order);

            Craft::$app->getQueue()->push(new HubspotAssociationsSyncJob([
                'orderId' => $this->orderId,
                'payloadHash' => $this->payloadHash,
                'contactsAndCompanies' => $this->contactsAndCompanies,
                'courseMap' => $this->courseMap,
                'hubspotOrderId' => $hubspotOrderId,
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
        return 'Sync HubSpot order object for order #' . $this->orderId;
    }
}
