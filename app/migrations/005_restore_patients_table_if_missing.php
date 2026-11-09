<?php

class Restore_patients_table_if_missing
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        require_once APP_DIR . 'migrations/003_create_patients_table.php';
        $migration = new Create_patients_table();
        $migration->up();
    }

    public function down()
    {
        // The repair migration must not remove patient data on rollback.
    }
}
