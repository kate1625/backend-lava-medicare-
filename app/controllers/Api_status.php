<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_status extends Controller
{
    public function health()
    {
        $api = $this->call->library('api');
        $statement = $this->call->database()->raw('SELECT 1 AS connected');
        $database = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$database || (int) $database['connected'] !== 1) {
            $api->respond_error('Database connection failed.', 503);
        }

        $api->respond(['status' => 'ok', 'database' => 'connected']);
    }
}
