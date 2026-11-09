<?php

class Add_payment_plan_fields
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $db = $this->_lava->call->database();
        $paymentPlan = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['payments', 'payment_plan']
        )->fetchColumn();
        if (!$paymentPlan) {
            $db->raw("ALTER TABLE payments ADD COLUMN payment_plan ENUM('full','downpayment') NOT NULL DEFAULT 'full' AFTER amount");
        }

        $paymentPercent = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['payments', 'payment_percent']
        )->fetchColumn();
        if (!$paymentPercent) {
            $db->raw('ALTER TABLE payments ADD COLUMN payment_percent TINYINT UNSIGNED NULL AFTER payment_plan');
        }
    }

    public function down()
    {
        // Payment history should be retained.
    }
}
