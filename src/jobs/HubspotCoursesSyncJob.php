<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Craft;
use Throwable;
use yii\queue\Queue;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;

/**
 * Stage 2: sync courses.
 */
final class HubspotCoursesSyncJob extends AbstractHubspotSyncStageJob
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
     * Execute the courses stage and queue the order-record stage.
     *
     * @param Queue $queue
     *
     * @return void
     */
    public function execute($queue): void
    {
        try {
            $order = $this->requireOrder();
            $courseMap = CommerceHubspotIntegration::getInstance()
                ->getHubspotApiService()
                ->syncOrderCourses($order);

            Craft::$app->getQueue()->push(new HubspotOrderRecordSyncJob([
                'orderId' => $this->orderId,
                'payloadHash' => $this->payloadHash,
                'contactsAndCompanies' => $this->contactsAndCompanies,
                'courseMap' => $courseMap,
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
        return 'Sync HubSpot courses for order #' . $this->orderId;
    }
}
