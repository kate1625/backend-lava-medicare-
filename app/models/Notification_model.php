<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Notification_model extends Model
{
    protected $table = 'notifications';

    public function create(int $userId, ?int $senderId, string $title, string $message, string $type = 'general', string $roleTarget = 'patient', ?string $redirectUrl = null): int
    {
        $this->db->raw('INSERT INTO notifications (user_id, sender_id, role_target, title, message, type, redirect_url) VALUES (?, ?, ?, ?, ?, ?, ?)', [$userId, $senderId, $roleTarget, $title, $message, $type, $redirectUrl]);
        return (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function createOnce(int $userId, ?int $senderId, string $title, string $message, string $type, string $roleTarget, ?string $redirectUrl, string $eventKey): int
    {
        $this->db->raw(
            'INSERT INTO notifications (user_id, sender_id, role_target, title, message, type, redirect_url, event_key) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
            [$userId, $senderId, $roleTarget, $title, $message, $type, $redirectUrl, $eventKey]
        );
        return (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function createForRoles(array $roles, ?int $senderId, string $title, string $message, string $type, ?string $redirectUrl, string $eventKey, ?int $excludeUserId = null): void
    {
        if (!$roles) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $parameters = $roles;
        $sql = "SELECT u.user_id, r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id "
            . "WHERE u.status='active' AND r.role_name IN ($placeholders)";
        if ($excludeUserId !== null) {
            $sql .= ' AND u.user_id<>?';
            $parameters[] = $excludeUserId;
        }

        $recipients = $this->db->raw($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recipients as $recipient) {
            $this->createOnce(
                (int) $recipient['user_id'],
                $senderId,
                $title,
                $message,
                $type,
                strtolower((string) $recipient['role_name']),
                $redirectUrl,
                $eventKey
            );
        }
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->db->raw('SELECT id, user_id, sender_id, role_target, title, message, type, is_read, redirect_url, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT ?', [$userId, $limit])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->raw('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$userId])->fetchColumn();
    }

    public function markRead(int $id, int $userId): void
    {
        $this->db->raw('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?', [$id, $userId]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->raw('UPDATE notifications SET is_read=1 WHERE user_id=?', [$userId]);
    }
}