<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Craft;
use Throwable;
use yii\queue\Queue;
use craft\queue\BaseJob;
use craft\commerce\elements\Order;
use yii\queue\RetryableJobInterface;
use burnthebook\craftcommercehubspotintegration\records\HubspotSyncRecord;

/**
 * Dispatches staged HubSpot order sync jobs.
 */
final class HubspotOrderSyncJob extends BaseJob implements RetryableJobInterface
{
    /**
     * Craft Commerce order ID to sync.
     */
    public int $orderId;

    /**
     * Execute the dispatch stage for HubSpot order sync.
     *
     * @param Queue $queue
     *
     * @return void
     */
    public function execute($queue): void
    {
        Craft::info(
            'HubSpot sync job started for order ID ' . $this->orderId,
            'craft-commerce-hubspot-integration'
        );

        $record = HubspotSyncRecord::findOne(['orderId' => $this->orderId]);
        if (!$record instanceof HubspotSyncRecord) {
            $record = new HubspotSyncRecord();
            $record->orderId = $this->orderId;
            $record->status = HubspotSyncRecord::STATUS_PENDING;
            $record->attempts = 0;
            $record->payloadHash = '';
        }

        $order = Order::find()->id($this->orderId)->one();

        if (!$order instanceof Order) {
            $record->status = HubspotSyncRecord::STATUS_FAILED;
            $record->attempts++;
            $record->lastError = 'Order could not be found in Craft Commerce.';
            $record->save(false);

            Craft::warning(
                'Skipping HubSpot sync because order could not be found. Order ID: ' . $this->orderId,
                'craft-commerce-hubspot-integration'
            );

            return;
        }

        $payloadHash = hash('sha256', json_encode($order->toArray()) ?: '');

        if (
            $record->status === HubspotSyncRecord::STATUS_SUCCEEDED
            && $record->payloadHash === $payloadHash
        ) {
            Craft::info(
                'Skipping HubSpot sync for order #' . $this->orderId . ' because payload hash is unchanged.',
                'craft-commerce-hubspot-integration'
            );

            return;
        }

        $record->status = HubspotSyncRecord::STATUS_IN_PROGRESS;
        $record->attempts++;
        $record->payloadHash = $payloadHash;
        $record->lastError = null;
        $record->lastCorrelationId = null;
        $record->save(false);

        Craft::$app->getQueue()->push(new HubspotContactsCompaniesSyncJob([
            'orderId' => $this->orderId,
            'payloadHash' => $payloadHash,
        ]));
    }

    /**
     * Return a human-readable queue job description.
     *
     * @return string
     */
    protected function defaultDescription(): string
    {
        return 'Sync order #' . $this->orderId . ' to HubSpot';
    }

    /**
     * Number of seconds this job can run.
     *
     * @return int
     */
    public function getTtr(): int
    {
        return 120;
    }

    /**
     * Determine whether the job should retry after a failure.
     *
     * @param int $attempt
     * @param Throwable $error
     *
     * @return bool
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }
}
