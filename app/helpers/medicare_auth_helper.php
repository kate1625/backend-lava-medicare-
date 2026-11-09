<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

function medicare_audit($database, ?int $userId, string $action, ?string $tableName = null, ?int $recordId = null): void
{
    $database->raw(
        'INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address) VALUES (?, ?, ?, ?, ?)',
        [$userId, substr($action, 0, 30), $tableName, $recordId, $_SERVER['REMOTE_ADDR'] ?? null]
    );
}

function medicare_email_was_sent($database, int $appointmentId, int $patientId, string $type): bool
{
    $count = $database->raw(
        "SELECT COUNT(*) FROM email_notifications WHERE appointment_id=? AND patient_id=? AND type=? AND status='sent'",
        [$appointmentId, $patientId, mb_substr($type, 0, 30)]
    )->fetchColumn();
    return (int) $count > 0;
}

function medicare_log_email($database, int $appointmentId, int $patientId, string $type, string $subject, array $result, string $recipientType): void
{
    $database->raw(
        'INSERT INTO email_notifications (appointment_id, patient_id, type, subject, status, sent_at, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $appointmentId,
            $patientId,
            mb_substr($type, 0, 30),
            mb_substr($subject, 0, 150),
            $result['sent'] ? 'sent' : 'failed',
            $result['sent'] ? date('Y-m-d H:i:s') : null,
            $result['sent'] ? null : mb_substr((string) ($result['error'] ?? 'Email delivery failed.'), 0, 255),
        ]
    );
    if (!$result['sent']) {
        error_log('MediCare+ ' . $recipientType . ' email failed for appointment #' . $appointmentId . ': ' . ($result['error'] ?? 'Unknown email error.'));
    }
}

function medicare_log_patient_email($database, int $appointmentId, int $patientId, string $type, string $subject, array $result): void
{
    medicare_log_email($database, $appointmentId, $patientId, $type, $subject, $result, 'patient');
}

function medicare_access_token_ttl(): int
{
    $configuredTtl = filter_var(getenv('MEDICARE_ACCESS_TOKEN_TTL'), FILTER_VALIDATE_INT);
    if ($configuredTtl === false || $configuredTtl < 300 || $configuredTtl > 43200) {
        return 28800;
    }

    return $configuredTtl;
}

function medicare_issue_token($database, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $expiresIn = medicare_access_token_ttl();
    $database->raw(
        'INSERT INTO auth_tokens (user_id, token, expires_at, is_revoked) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . $expiresIn . ' SECOND), ?)',
        [$userId, hash('sha256', $token), 0]
    );
    return $token;
}

function medicare_authenticate($database, $api): array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches)) {
        $api->respond_error('Authentication required.', 401);
    }

    $statement = $database->raw(
        'SELECT u.user_id, u.username, u.email, u.status, r.role_name FROM auth_tokens t JOIN users u ON u.user_id=t.user_id JOIN roles r ON r.role_id=u.role_id WHERE t.token=? AND t.is_revoked=0 AND t.expires_at>NOW() LIMIT 1',
        [hash('sha256', $matches[1])]
    );
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['status'] !== 'active') {
        $api->respond_error('Your session has expired. Please sign in again.', 401);
    }
    return $user;
}

function medicare_require_role($database, $api, array $allowedRoles): array
{
    $user = medicare_authenticate($database, $api);
    if (!in_array($user['role_name'], $allowedRoles, true)) {
        $api->respond_error('You do not have permission to access this resource.', 403);
    }
    return $user;
}
