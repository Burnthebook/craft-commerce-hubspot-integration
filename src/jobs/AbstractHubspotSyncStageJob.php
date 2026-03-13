<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Craft;
use Throwable;
use craft\queue\BaseJob;
use craft\commerce\elements\Order;
use yii\queue\RetryableJobInterface;
use burnthebook\craftcommercehubspotintegration\records\HubspotSyncRecord;
use burnthebook\craftcommercehubspotintegration\exceptions\HubspotApiException;

/**
 * Base class for staged HubSpot sync jobs.
 */
abstract class AbstractHubspotSyncStageJob extends BaseJob implements RetryableJobInterface
{
    /**
     * Craft Commerce order ID.
     */
    public int $orderId;

    /**
     * Payload hash calculated at dispatch stage.
     */
    public string $payloadHash = '';

    /**
     * Return whether a failed stage should be retried.
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

    /**
     * Return the max time-to-run for staged jobs.
     *
     * @return int
     */
    public function getTtr(): int
    {
        return 120;
    }

    /**
     * Resolve the current order or fail the stage.
     *
     * @return Order
     */
    protected function requireOrder(): Order
    {
        $order = Order::find()->id($this->orderId)->one();

        if (!$order instanceof Order) {
            throw new \RuntimeException('Order could not be found in Craft Commerce.');
        }

        return $order;
    }

    /**
     * Persist failure details to the sync record.
     *
     * @param Throwable $exception
     *
     * @return void
     */
    protected function markFailed(Throwable $exception): void
    {
        $record = HubspotSyncRecord::findOne(['orderId' => $this->orderId]);
        if (!$record instanceof HubspotSyncRecord) {
            $record = new HubspotSyncRecord();
            $record->orderId = $this->orderId;
            $record->payloadHash = $this->payloadHash;
            $record->attempts = 1;
        }

        $record->status = HubspotSyncRecord::STATUS_FAILED;
        $record->lastError = $exception->getMessage();

        if ($exception instanceof HubspotApiException) {
            $record->lastCorrelationId = $exception->getCorrelationId();
        }

        $record->save(false);

        Craft::error(
            sprintf('HubSpot sync stage failed for order %d: %s', $this->orderId, $exception->getMessage()),
            'craft-commerce-hubspot-integration'
        );
    }
}
