<?php

declare(strict_types=1);

namespace burnthebook\craftcommercehubspotintegration\migrations;

use craft\db\Migration;

/**
 * Creates table for HubSpot order sync state tracking.
 */
final class m260312_000001_create_hubspot_sync_records_table extends Migration
{
    /**
     * Database table for sync records.
     */
    private const string TABLE_NAME = '{{%btb_hubspot_sync_records}}';

    /**
     * Apply the migration.
     *
     * @return bool
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE_NAME)) {
            return true;
        }

        $this->createTable(self::TABLE_NAME, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'payloadHash' => $this->char(64)->notNull()->defaultValue(''),
            'syncedAt' => $this->dateTime(),
            'lastError' => $this->text(),
            'lastCorrelationId' => $this->string(64),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            null,
            self::TABLE_NAME,
            ['orderId'],
            true
        );

        $this->createIndex(
            null,
            self::TABLE_NAME,
            ['status'],
            false
        );

        return true;
    }

    /**
     * Revert the migration.
     *
     * @return bool
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_NAME);

        return true;
    }
}
