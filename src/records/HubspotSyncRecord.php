<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for HubSpot order sync state.
 *
 * @property int $id
 * @property int $orderId
 * @property string $status
 * @property int $attempts
 * @property string $payloadHash
 * @property string|null $syncedAt
 * @property string|null $lastError
 * @property string|null $lastCorrelationId
 */
final class HubspotSyncRecord extends ActiveRecord
{
    /**
     * Sync record is queued and waiting to run.
     */
    public const string STATUS_PENDING = 'pending';

    /**
     * Sync record is currently being processed.
     */
    public const string STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Sync record completed successfully.
     */
    public const string STATUS_SUCCEEDED = 'succeeded';

    /**
     * Sync record failed.
     */
    public const string STATUS_FAILED = 'failed';

    /**
     * Return the ActiveRecord table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%btb_hubspot_sync_records}}';
    }
}
