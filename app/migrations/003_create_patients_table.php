<?php

class Create_patients_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('patients')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'created_by' => [
                    'type'     => 'INT',
                    'constraint' => 11,
                    'unsigned' => TRUE,
                    'null'     => TRUE,
                    'default'  => NULL,
                ],
                'first_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 80,
                    'null'       => FALSE,
                ],
                'last_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 80,
                    'null'       => FALSE,
                ],
                'email' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => TRUE,
                    'default'    => NULL,
                ],
                'phone' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'null'       => TRUE,
                    'default'    => NULL,
                ],
                'date_of_birth' => [
                    'type'    => 'DATE',
                    'null'    => TRUE,
                    'default' => NULL,
                ],
                'gender' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 24,
                    'null'       => FALSE,
                ],
                'address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => TRUE,
                    'default'    => NULL,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => FALSE,
                    'default'    => 'active',
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => TRUE,
                    'default' => NULL,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('created_by', name: 'patients_created_by_idx')
            ->add_key('last_name', name: 'patients_last_name_idx')
            ->add_foreign_key('created_by', 'users', 'id', 'SET NULL')
            ->create_table('patients');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('patients');
    }
}
