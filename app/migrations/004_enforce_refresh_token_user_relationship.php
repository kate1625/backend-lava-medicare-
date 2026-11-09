<?php

class Enforce_refresh_token_user_relationship
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $statement = $this->_lava->db->raw(
            "SELECT COUNT(*) AS constraint_count
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'refresh_tokens'
               AND CONSTRAINT_NAME = 'refresh_tokens_user_fk'"
        );
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ((int) ($result['constraint_count'] ?? 0) === 0) {
            $this->_lava->db->raw(
                'ALTER TABLE refresh_tokens
                 ADD CONSTRAINT refresh_tokens_user_fk
                 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
            );
        }
    }

    public function down()
    {
        $this->_lava->db->raw(
            'ALTER TABLE refresh_tokens DROP FOREIGN KEY refresh_tokens_user_fk'
        );
    }
}
