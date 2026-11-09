<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Notifications extends Controller
{
    private function context($api): array
    {
        $database = $this->call->database();
        $user = medicare_require_role($database, $api, ['Admin', 'Staff', 'Patient']);
        return [$database, $user];
    }

    public function index()
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->context($api);
        $model = $this->call->model('Notification_model');
        $items = $model->forUser((int) $user['user_id']);
        $api->respond(['data' => $items]);
    }

    public function unread()
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->context($api);
        $model = $this->call->model('Notification_model');
        $api->respond(['data' => ['count' => $model->unreadCount((int) $user['user_id'])]]);
    }

    public function read($id)
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->context($api);
        $model = $this->call->model('Notification_model');
        $model->markRead((int) $id, (int) $user['user_id']);
        $api->respond(['message' => 'Marked as read.']);
    }

    public function readAll()
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->context($api);
        $model = $this->call->model('Notification_model');
        $model->markAllRead((int) $user['user_id']);
        $api->respond(['message' => 'All marked as read.']);
    }
}