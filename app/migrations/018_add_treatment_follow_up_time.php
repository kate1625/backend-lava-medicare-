<?php

class Add_treatment_follow_up_time
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
            ['treatment_records', 'follow_up_time']
        )->fetchColumn();

        if (!$columnExists) {
            $db->raw('ALTER TABLE treatment_records ADD COLUMN follow_up_time TIME NULL AFTER follow_up_date');
        }
    }

    public function down()
    {
        // Keep scheduled return times when rolling back application code.
    }
}
