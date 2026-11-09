<?php

class Add_notification_event_key
{
    private $_lava;

    public function __construct() { $this->_lava = lava_instance(); }

    public function up()
    {
        $db = $this->_lava->call->database();
        $exists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['notifications', 'event_key']
        )->fetchColumn();
        if (!$exists) {
            $db->raw('ALTER TABLE notifications ADD COLUMN event_key VARCHAR(120) NULL AFTER type');
        }

        $indexExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            ['notifications', 'uq_notification_recipient_event']
        )->fetchColumn();
        if (!$indexExists) {
            $db->raw('ALTER TABLE notifications ADD UNIQUE KEY uq_notification_recipient_event (user_id, event_key)');
        }
    }

    public function down() {}
}
