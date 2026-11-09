<?php

class Create_workspace_features
{
    private $_lava;

    public function __construct() { $this->_lava = lava_instance(); }

    public function up()
    {
        $db = $this->_lava->call->database();
        $db->raw("CREATE TABLE IF NOT EXISTS prescriptions (
            prescription_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            created_by INT NOT NULL,
            medication VARCHAR(190) NOT NULL,
            directions TEXT NOT NULL,
            status ENUM('active','review','completed','cancelled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(patient_id),
            FOREIGN KEY (created_by) REFERENCES users(user_id)
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS clinic_messages (
            message_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            recipient_id INT NOT NULL,
            body TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sender_id) REFERENCES users(user_id),
            FOREIGN KEY (recipient_id) REFERENCES users(user_id),
            INDEX idx_conversation (sender_id, recipient_id, created_at),
            INDEX idx_recipient_unread (recipient_id, is_read, created_at)
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS clinic_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $db->raw("CREATE TABLE IF NOT EXISTS tooth_conditions (
            patient_id INT NOT NULL,
            tooth_no VARCHAR(2) NOT NULL,
            condition_name ENUM('Healthy','Decayed','Filled','Crown','Root Canal','Extracted') NOT NULL DEFAULT 'Healthy',
            updated_by INT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (patient_id, tooth_no),
            FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(user_id)
        ) ENGINE=InnoDB");
    }

    public function down() {}
}
