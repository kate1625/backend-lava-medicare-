<?php

class Allow_unavailable_date_overrides
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $nullableColumns = [
            'day_of_week' => 'TINYINT',
            'start_time' => 'TIME',
            'end_time' => 'TIME',
        ];

        foreach ($nullableColumns as $column => $type) {
            $isNullable = $db->raw(
                'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
                ['dentist_schedules', $column]
            )->fetchColumn();

            if ($isNullable === 'NO') {
                $db->raw("ALTER TABLE dentist_schedules MODIFY COLUMN {$column} {$type} NULL");
            }
        }
    }

    public function down() {}
}
