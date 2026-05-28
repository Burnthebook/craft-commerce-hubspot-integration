<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\jobs;

use Craft;
use Throwable;
use yii\queue\Queue;
use craft\queue\BaseJob;
use craft\base\ElementInterface;
use yii\queue\RetryableJobInterface;
use burnthebook\craftcommercehubspotintegration\CommerceHubspotIntegration;
use burnthebook\craftcommercehubspotintegration\exceptions\HubspotApiException;
use burnthebook\craftcommercehubspotintegration\records\HubspotCourseSyncRecord;

/**
 * Syncs a single CMS element to a HubSpot course record.
 */
final class HubspotCourseProvisioningJob extends BaseJob implements RetryableJobInterface
{
    public int $elementId;

    public int $siteId;

    public function execute($queue): void
    {
        $record = HubspotCourseSyncRecord::findOne([
            'elementId' => $this->elementId,
            'siteId' => $this->siteId,
        ]);

        if (!$record instanceof HubspotCourseSyncRecord) {
            $record = new HubspotCourseSyncRecord();
            $record->elementId = $this->elementId;
            $record->siteId = $this->siteId;
            $record->status = HubspotCourseSyncRecord::STATUS_PENDING;
            $record->attempts = 0;
            $record->payloadHash = '';
        }

        $element = $this->loadElement();
        if ($element === null) {
            $record->status = HubspotCourseSyncRecord::STATUS_FAILED;
            $record->attempts++;
            $record->lastError = 'Course provisioning source element could not be loaded.';
            $record->save(false);
            return;
        }

        $service = CommerceHubspotIntegration::getInstance()->getHubspotCourseProvisioningService();
        $payloadHash = $service->payloadHash($element);

        if (
            $record->status === HubspotCourseSyncRecord::STATUS_SUCCEEDED
            && $record->payloadHash === $payloadHash
        ) {
            Craft::info(
                sprintf('Skipping HubSpot course provisioning for element %d (site %d): payload unchanged.', $this->elementId, $this->siteId),
                'craft-commerce-hubspot-integration'
            );
            return;
        }

        $record->status = HubspotCourseSyncRecord::STATUS_IN_PROGRESS;
        $record->attempts++;
        $record->payloadHash = $payloadHash;
        $record->lastError = null;
        $record->lastCorrelationId = null;
        $record->save(false);

        try {
            $hubspotId = $service->provisionCourse($element);
            $record->status = HubspotCourseSyncRecord::STATUS_SUCCEEDED;
            $record->hubspotObjectId = $hubspotId;
            $record->syncedAt = Craft::$app->getFormatter()->asDatetime('now', 'php:Y-m-d H:i:s');
            $record->save(false);
        } catch (Throwable $exception) {
            $record->status = HubspotCourseSyncRecord::STATUS_FAILED;
            $record->lastError = $exception->getMessage();
            if ($exception instanceof HubspotApiException) {
                $record->lastCorrelationId = $exception->getCorrelationId();
            }
            $record->save(false);
            throw $exception;
        }
    }

    protected function defaultDescription(): string
    {
        return sprintf('Provision HubSpot course for element #%d (site %d)', $this->elementId, $this->siteId);
    }

    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }

    private function loadElement(): ?ElementInterface
    {
        $element = Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId);
        return $element instanceof ElementInterface ? $element : null;
    }
}
