<?php

class Add_reschedule_reason
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $exists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['appointments', 'reschedule_reason']
        )->fetchColumn();
        if (!$exists) {
            $db->raw('ALTER TABLE appointments ADD COLUMN reschedule_reason VARCHAR(255) NULL AFTER cancel_reason');
        }
    }

    public function down()
    {
        // Appointment history should be retained.
    }
}
