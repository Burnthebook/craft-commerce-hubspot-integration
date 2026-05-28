<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\migrations;

use craft\db\Migration;

/**
 * Creates table for HubSpot CMS course provisioning sync state.
 */
final class m260521_000002_create_hubspot_course_sync_records_table extends Migration
{
    private const TABLE_NAME = '{{%btb_hubspot_course_sync_records}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE_NAME)) {
            return true;
        }

        $this->createTable(self::TABLE_NAME, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'payloadHash' => $this->char(64)->notNull()->defaultValue(''),
            'hubspotObjectId' => $this->string(32),
            'syncedAt' => $this->dateTime(),
            'lastError' => $this->text(),
            'lastCorrelationId' => $this->string(64),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_NAME, ['elementId', 'siteId'], true);
        $this->createIndex(null, self::TABLE_NAME, ['status'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_NAME);

        return true;
    }
}
