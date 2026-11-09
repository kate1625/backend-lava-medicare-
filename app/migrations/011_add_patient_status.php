<?php

class Add_patient_status
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $statusColumn = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['patients', 'status']
        )->fetchColumn();

        if (!$statusColumn) {
            $db->raw("ALTER TABLE patients ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
        }
    }

    public function down()
    {
    }
}
