<?php

class Link_prescriptions_to_appointments
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
            ['prescriptions', 'appointment_id']
        )->fetchColumn();
        if (!$columnExists) {
            $db->raw('ALTER TABLE prescriptions ADD COLUMN appointment_id INT NULL AFTER patient_id');
        }

        $indexExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            ['prescriptions', 'idx_prescriptions_appointment']
        )->fetchColumn();
        if (!$indexExists) {
            $db->raw('ALTER TABLE prescriptions ADD INDEX idx_prescriptions_appointment (appointment_id)');
        }

        $foreignKeyExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND column_name=? AND referenced_table_name=?',
            ['prescriptions', 'appointment_id', 'appointments']
        )->fetchColumn();
        if (!$foreignKeyExists) {
            $db->raw('ALTER TABLE prescriptions ADD CONSTRAINT fk_prescriptions_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE SET NULL');
        }
    }

    public function down() {}
}
