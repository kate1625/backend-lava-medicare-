<?php

class Create_notifications_table
{
    private $_lava;

    public function __construct() { $this->_lava = lava_instance(); }

    public function up()
    {
        $db = $this->_lava->call->database();
        $db->raw("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sender_id INT NULL,
            role_target VARCHAR(20) NOT NULL DEFAULT 'patient',
            title VARCHAR(190) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'general',
            event_key VARCHAR(120) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            redirect_url VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE SET NULL,
            UNIQUE KEY uq_notification_recipient_event (user_id, event_key),
            INDEX idx_user_read (user_id, is_read, created_at)
        ) ENGINE=InnoDB");
    }

    public function down() {}
}