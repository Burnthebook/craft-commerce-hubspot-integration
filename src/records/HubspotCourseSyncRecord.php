<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for CMS-driven HubSpot course provisioning sync state.
 */
final class HubspotCourseSyncRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%btb_hubspot_course_sync_records}}';
    }
}
