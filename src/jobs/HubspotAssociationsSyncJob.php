<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Throwable;
use yii\queue\Queue;
use craft\helpers\DateTimeHelper;
use burnthebook\craftcommercehubspotintegration\records\HubspotSyncRecord;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;

/**
 * Stage 4: sync all HubSpot associations and finalize sync record.
 */
final class HubspotAssociationsSyncJob extends AbstractHubspotSyncStageJob
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
     * HubSpot order ID produced in the order stage.
     */
    public string $hubspotOrderId = '';

    /**
     * Execute the associations stage and finalize the sync record.
     *
     * @param Queue $queue
     *
     * @return void
     */
    public function execute($queue): void
    {
        try {
            $order = $this->requireOrder();

            CommerceHubspotIntegration::getInstance()
                ->getHubspotApiService()
                ->syncOrderAssociations(
                    order: $order,
                    orderHubspotId: $this->hubspotOrderId,
                    bookerContactId: $this->contactsAndCompanies['bookerContactId'],
                    bookerCompanyId: $this->contactsAndCompanies['bookerCompanyId'],
                    delegates: $this->contactsAndCompanies['delegates'],
                    courseMap: $this->courseMap
                );

            $record = HubspotSyncRecord::findOne(['orderId' => $this->orderId]);
            if ($record instanceof HubspotSyncRecord) {
                $record->status = HubspotSyncRecord::STATUS_SUCCEEDED;
                $record->syncedAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
                $record->lastError = null;
                $record->lastCorrelationId = null;
                $record->save(false);
            }
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
        return 'Sync HubSpot associations for order #' . $this->orderId;
    }
}
