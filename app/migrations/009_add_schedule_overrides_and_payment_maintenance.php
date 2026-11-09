<?php

class Add_schedule_overrides_and_payment_maintenance
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $paymentColumn = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['payment_methods', 'is_maintenance']
        )->fetchColumn();
        if (!$paymentColumn) {
            $db->raw('ALTER TABLE payment_methods ADD COLUMN is_maintenance BOOLEAN NOT NULL DEFAULT FALSE');
        }

        $scheduleDate = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['dentist_schedules', 'schedule_date']
        )->fetchColumn();
        if (!$scheduleDate) {
            $db->raw('ALTER TABLE dentist_schedules MODIFY COLUMN day_of_week TINYINT NULL');
            $db->raw('ALTER TABLE dentist_schedules ADD COLUMN schedule_date DATE NULL AFTER day_of_week');
        }

        $availableColumn = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['dentist_schedules', 'is_available']
        )->fetchColumn();
        if (!$availableColumn) {
            $db->raw('ALTER TABLE dentist_schedules ADD COLUMN is_available BOOLEAN NOT NULL DEFAULT TRUE');
        }

        $scheduleIndex = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            ['dentist_schedules', 'uq_dentist_schedule_date']
        )->fetchColumn();
        if (!$scheduleIndex) {
            $db->raw('ALTER TABLE dentist_schedules ADD UNIQUE KEY uq_dentist_schedule_date (dentist_id, schedule_date)');
        }
    }

    public function down()
    {
        // Existing schedules and payment configuration should be retained.
    }
}
