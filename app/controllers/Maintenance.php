<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Maintenance extends Controller
{
    public function migrate()
    {
        if (!defined('IS_CLI') || !IS_CLI) {
            show_404();
        }

        $migration = $this->call->library('migration');
        $migration->migrate();
    }
}
