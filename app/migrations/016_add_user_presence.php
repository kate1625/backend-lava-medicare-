<?php

class Add_user_presence
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
            ['users', 'last_seen_at']
        )->fetchColumn();

        if (!$columnExists) {
            $db->raw('ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER last_login');
        }
    }

    public function down() {}
}
