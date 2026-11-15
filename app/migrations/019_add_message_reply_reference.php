<?php

class Add_message_reply_reference
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $columnExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['clinic_messages', 'reply_to_message_id']
        )->fetchColumn();

        if (!$columnExists) {
            $db->raw('ALTER TABLE clinic_messages ADD COLUMN reply_to_message_id BIGINT NULL AFTER body');
        }

        $indexExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            ['clinic_messages', 'idx_clinic_messages_reply_to']
        )->fetchColumn();

        if (!$indexExists) {
            $db->raw('ALTER TABLE clinic_messages ADD INDEX idx_clinic_messages_reply_to (reply_to_message_id)');
        }

        $constraintExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name=? AND constraint_name=?',
            ['clinic_messages', 'fk_clinic_messages_reply_to']
        )->fetchColumn();

        if (!$constraintExists) {
            $db->raw('ALTER TABLE clinic_messages ADD CONSTRAINT fk_clinic_messages_reply_to FOREIGN KEY (reply_to_message_id) REFERENCES clinic_messages(message_id) ON DELETE SET NULL');
        }
    }

    public function down()
    {
        // Keep reply context when rolling back application code.
    }
}
