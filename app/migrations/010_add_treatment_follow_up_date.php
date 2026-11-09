<?php

class Add_treatment_follow_up_date
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $followUpColumn = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['treatment_records', 'follow_up_date']
        )->fetchColumn();

        if (!$followUpColumn) {
            $db->raw('ALTER TABLE treatment_records ADD COLUMN follow_up_date DATE NULL AFTER notes');
        }
    }

    public function down()
    {
        // Existing treatment and follow-up history should be retained.
    }
}
