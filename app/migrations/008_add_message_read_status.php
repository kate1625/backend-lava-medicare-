<?php

class Add_message_read_status
{
    private $_lava;

    public function __construct() { $this->_lava = lava_instance(); }

    public function up()
    {
        $db = $this->_lava->call->database();
        $columnExists = (int)$db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['clinic_messages', 'is_read']
        )->fetchColumn();

        if (!$columnExists) {
            $db->raw('ALTER TABLE clinic_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 1 AFTER body');
            $db->raw('ALTER TABLE clinic_messages MODIFY COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0');
        }

        $indexExists = (int)$db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            ['clinic_messages', 'idx_recipient_unread']
        )->fetchColumn();
        if (!$indexExists) {
            $db->raw('ALTER TABLE clinic_messages ADD INDEX idx_recipient_unread (recipient_id, is_read, created_at)');
        }
    }

    public function down()
    {
        $db = $this->_lava->call->database();
        $indexExists = (int)$db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            ['clinic_messages', 'idx_recipient_unread']
        )->fetchColumn();
        if ($indexExists) {
            $db->raw('ALTER TABLE clinic_messages DROP INDEX idx_recipient_unread');
        }

        $columnExists = (int)$db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['clinic_messages', 'is_read']
        )->fetchColumn();
        if ($columnExists) {
            $db->raw('ALTER TABLE clinic_messages DROP COLUMN is_read');
        }
    }
}
