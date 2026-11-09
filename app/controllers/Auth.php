<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Auth extends Controller
{
    public function login()
    {
        $api = $this->call->library('api');
        $api->require_method('POST');
        $api->rate_limit('medicare_login', 10, 60);
        $database = $this->call->database();
        $body = $api->body();
        $identity = trim((string) ($body['identity'] ?? $body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($identity === '' || $password === '') {
            $api->respond_error('Username/email and password are required.', 422);
        }

        $statement = $database->raw(
            'SELECT u.user_id, u.username, u.email, u.password_hash, u.is_verified, u.status, u.failed_login_attempts, u.locked_until, r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE (u.email=? OR u.username=?) AND u.status<>? LIMIT 1',
            [$identity, $identity, 'inactive']
        );
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            medicare_audit($database, null, 'LOGIN_FAILED', 'users', null);
            $api->respond_error('Invalid username/email or password.', 401);
        }

        if ($user['status'] === 'locked' && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            medicare_audit($database, (int) $user['user_id'], 'LOGIN_LOCKED', 'users', (int) $user['user_id']);
            $api->respond_error('Account is temporarily locked. Try again after 15 minutes.', 423);
        }
        if ($user['status'] === 'locked') {
            $database->raw('UPDATE users SET status=?, failed_login_attempts=0, locked_until=NULL WHERE user_id=?', ['active', (int) $user['user_id']]);
            $user['status'] = 'active';
            $user['failed_login_attempts'] = 0;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $database->raw('UPDATE users SET failed_login_attempts=failed_login_attempts+1 WHERE user_id=?', [(int) $user['user_id']]);
            $attemptStatement = $database->raw('SELECT failed_login_attempts FROM users WHERE user_id=?', [(int) $user['user_id']]);
            $attempts = (int) $attemptStatement->fetchColumn();
            if ($attempts >= 5) {
                $database->raw('UPDATE users SET status=?, locked_until=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id=?', ['locked', (int) $user['user_id']]);
            }
            medicare_audit($database, (int) $user['user_id'], 'LOGIN_FAILED', 'users', (int) $user['user_id']);
            $api->respond_error('Invalid username/email or password.', 401);
        }
        if (!(int) $user['is_verified']) {
            medicare_audit($database, (int) $user['user_id'], 'LOGIN_UNVERIFIED', 'users', (int) $user['user_id']);
            $api->respond_error('Verify your email address before signing in.', 403);
        }

        $database->raw('UPDATE users SET failed_login_attempts=0, locked_until=NULL, last_login=NOW() WHERE user_id=?', [(int) $user['user_id']]);
        $token = medicare_issue_token($database, (int) $user['user_id']);
        medicare_audit($database, (int) $user['user_id'], 'LOGIN', 'users', (int) $user['user_id']);
        $api->respond([
            'user' => [
                'id' => (int) $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => strtolower($user['role_name']),
            ],
            'tokens' => ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 900],
        ]);
    }

    public function logout()
    {
        $api = $this->call->library('api');
        $api->require_method('POST');
        $database = $this->call->database();
        $user = medicare_authenticate($database, $api);
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authorization === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches);
        $database->raw('UPDATE auth_tokens SET is_revoked=? WHERE token=?', [1, hash('sha256', $matches[1])]);
        medicare_audit($database, (int) $user['user_id'], 'LOGOUT', 'auth_tokens', null);
        $api->respond(['message' => 'Signed out successfully.']);
    }

    public function register()
    {
        $api = $this->call->library('api'); $api->require_method('POST'); $db = $this->call->database(); $body = $api->body();
        $username = trim((string) ($body['username'] ?? '')); $email = strtolower(trim((string) ($body['email'] ?? ''))); $password = (string) ($body['password'] ?? '');
        $first = trim((string) ($body['first_name'] ?? '')); $last = trim((string) ($body['last_name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100 || strlen($password) < 8 || mb_strlen($first) < 1 || mb_strlen($first) > 50 || mb_strlen($last) < 1 || mb_strlen($last) > 50) $api->respond_error('Enter a valid username, email, password (at least 8 characters), first name, and last name.', 422);

        $role = $db->raw('SELECT role_id FROM roles WHERE role_name=?', ['Patient'])->fetch(PDO::FETCH_ASSOC);
        if (!$role) $api->respond_error('Patient role is missing from the database.', 503);

        // Registration follows the fixed 15-table schema: an unverified user,
        // patient profile, and email_verifications row are created immediately.
        $existing = $db->raw(
            'SELECT u.user_id, u.username, u.email, u.password_hash, u.is_verified, r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.username=? OR u.email=? LIMIT 1',
            [$username, $email]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (strtolower($existing['email']) === $email
                && strtolower($existing['role_name']) === 'patient'
                && (int) $existing['is_verified'] === 1) {
                $api->respond_error('This account is already registered and verified. Please sign in.', 409);
            }
            $canResend = strtolower($existing['email']) === $email
                && $existing['username'] === $username
                && strtolower($existing['role_name']) === 'patient'
                && !(int) $existing['is_verified']
                && password_verify($password, $existing['password_hash']);
            if (!$canResend) $api->respond_error('That username or email is already registered. If this is your unverified account, use the same username and password to get a new verification code.', 409);

            $userId = (int) $existing['user_id'];
            $code = (string) random_int(100000, 999999);
            $emailResult = $this->sendVerificationEmail($email, $first . ' ' . $last, $code);
            if (!$emailResult['sent']) {
                $api->respond(['data' => [
                    'message' => 'We could not send a verification code. ' . $emailResult['error'],
                    'email' => $email,
                    'email_sent' => false,
                ]]);
            }
            $db->raw('UPDATE email_verifications SET is_used=1 WHERE user_id=? AND is_used=0', [$userId]);
            $db->raw('INSERT INTO email_verifications (user_id, code, expires_at, is_used) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), ?)', [$userId, $code, 0]);
            medicare_audit($db, $userId, 'RESEND_VERIFICATION', 'users', $userId);
            $data = [
                'message' => $emailResult['sent']
                    ? 'Your account is awaiting verification. A new verification code was sent to your email address.'
                    : 'Your account is awaiting verification, but the email could not be sent. ' . $emailResult['error'],
                'email' => $email,
                'email_sent' => $emailResult['sent'],
            ];
            $api->respond(['data' => $data]);
        }

        $code = (string) random_int(100000, 999999);
        try {
            $db->raw('START TRANSACTION');
            $db->raw(
                'INSERT INTO users (role_id, username, email, password_hash, is_verified, status) VALUES (?, ?, ?, ?, 0, ?)',
                [(int) $role['role_id'], $username, $email, password_hash($password, PASSWORD_BCRYPT), 'active']
            );
            $userId = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            $db->raw('INSERT INTO patients (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)', [$userId, $first, $last, $email]);
            $db->raw('INSERT INTO email_verifications (user_id, code, expires_at, is_used) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 0)', [$userId, $code]);
            medicare_audit($db, $userId, 'REGISTER_PENDING', 'users', $userId);
            $db->raw('COMMIT');
        } catch (Throwable $databaseError) {
            $db->raw('ROLLBACK');
            error_log('Patient registration failed: ' . $databaseError->getMessage());
            $api->respond_error('We could not create the account. Check that the database has the required 15-table schema and try again.', 500);
        }

        $emailResult = $this->sendVerificationEmail($email, $first . ' ' . $last, $code);
        if ($emailResult['sent']) $db->raw('UPDATE email_verifications SET expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE user_id=? AND code=? AND is_used=0', [$userId, $code]);
        $data = [
            'message' => $emailResult['sent']
                ? 'A verification code was sent. Enter it to finish creating your account.'
                : 'Your account is waiting for email verification, but the code could not be sent. Choose “Send code again” to retry. ' . $emailResult['error'],
            'email' => $email,
            'email_sent' => $emailResult['sent'],
        ];
        $api->respond(['data' => $data]);
    }

    /** Send verification codes server-side so production never exposes them to the browser. */
    private function sendVerificationEmail($email, $name, $code)
    {
        $serviceId = trim((string) getenv('EMAILJS_SERVICE_ID'));
        $templateId = trim((string) getenv('EMAILJS_VERIFICATION_TEMPLATE_ID'));
        $publicKey = trim((string) getenv('EMAILJS_PUBLIC_KEY'));
        $privateKey = trim((string) getenv('EMAILJS_PRIVATE_KEY'));
        if ($serviceId === '' || $templateId === '' || $publicKey === '') {
            return ['sent' => false, 'error' => 'Email service settings are missing on the backend.'];
        }
        if (!function_exists('curl_init')) {
            return ['sent' => false, 'error' => 'The backend PHP cURL extension is not enabled.'];
        }

        // PHP for Windows does not always ship with a configured CA store.
        // Keep TLS verification enabled and point cURL at the bundled public roots.
        $caBundle = trim((string) getenv('EMAILJS_CA_BUNDLE'));
        if ($caBundle === '') {
            $caBundle = APP_DIR . 'config/certs/mozilla-cacert.pem';
        } elseif (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $caBundle)) {
            $caBundle = APP_DIR . ltrim($caBundle, '/\\\\');
        }
        if (!is_file($caBundle) || !is_readable($caBundle)) {
            return ['sent' => false, 'error' => 'The backend CA certificate bundle is missing or unreadable. Check EMAILJS_CA_BUNDLE.'];
        }

        $payload = json_encode([
            'service_id' => $serviceId,
            'template_id' => $templateId,
            'user_id' => $publicKey,
            'accessToken' => $privateKey,
            'template_params' => [
                'to_email' => $email,
                'to_name' => $name,
                'verification_code' => $code,
                'app_name' => 'MediCare+',
                'expires_in_minutes' => 15,
            ],
        ]);
        $curl = curl_init('https://api.emailjs.com/api/v1.0/email/send');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CAINFO => $caBundle,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response !== false && $status >= 200 && $status < 300) {
            return ['sent' => true, 'error' => ''];
        }
        $providerMessage = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response)));
        $providerMessage = substr($providerMessage, 0, 240);
        error_log('EmailJS verification send failed. HTTP ' . $status . ($curlError !== '' ? ': ' . $curlError : '') . ($providerMessage !== '' ? ' Response: ' . $providerMessage : ''));

        if (strtolower((string) getenv('APP_ENV')) !== 'production') {
            $detail = $curlError !== '' ? $curlError : ($providerMessage !== '' ? $providerMessage : 'No response body was returned.');
            return ['sent' => false, 'error' => 'EmailJS request failed (HTTP ' . $status . '): ' . $detail];
        }
        return ['sent' => false, 'error' => 'Email provider rejected the message or is unreachable. Check backend logs and EmailJS template settings.'];
    }

    public function verifyEmail()
    {
        $api = $this->call->library('api');
        $api->require_method('POST');
        $db = $this->call->database();
        $body = $api->body();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            $api->respond_error('Enter a valid email and the six-digit verification code.', 422);
        }

        $row = $db->raw('SELECT ev.verification_id, ev.code, ev.expires_at, ev.is_used, u.user_id FROM email_verifications ev JOIN users u ON u.user_id=ev.user_id WHERE u.email=? AND u.is_verified=0 AND ev.is_used=0 ORDER BY ev.verification_id DESC LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $verifiedAccount = $db->raw('SELECT user_id FROM users WHERE email=? AND is_verified=1 LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC);
            if ($verifiedAccount) $api->respond_error('This account is already registered and verified. Please sign in.', 409);
            $api->respond_error('No active verification was found. Request a new code.', 422);
        }
        $failedAttempts = (int) $db->raw(
            'SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND action=? AND table_name=? AND created_at>=DATE_SUB(NOW(), INTERVAL 30 MINUTE)',
            [(int) $row['user_id'], 'VERIFY_FAILED', 'email_verifications']
        )->fetchColumn();
        if ($failedAttempts >= 3) $api->respond_error('Too many incorrect codes. Verification is paused for 30 minutes.', 429);
        $validUntil = $db->raw('SELECT (? > UTC_TIMESTAMP())', [$row['expires_at']])->fetchColumn();
        if (!(int) $validUntil) $api->respond_error('This code has expired. Request a new verification code.', 422);
        if (!hash_equals((string) $row['code'], $code)) {
            medicare_audit($db, (int) $row['user_id'], 'VERIFY_FAILED', 'email_verifications', (int) $row['verification_id']);
            if ($failedAttempts >= 2) $api->respond_error('Invalid code. Three attempts were used, so verification is paused for 30 minutes.', 429);
            $api->respond_error('Invalid code, please try again.', 422);
        }
        $db->raw('UPDATE email_verifications SET is_used=1 WHERE verification_id=?', [(int) $row['verification_id']]);
        $db->raw('UPDATE users SET is_verified=1 WHERE user_id=?', [(int) $row['user_id']]);
        medicare_audit($db, (int) $row['user_id'], 'VERIFY_EMAIL', 'users', (int) $row['user_id']);
        $api->respond(['message' => 'Email verified. You can now sign in.']);
    }

    public function resendVerificationEmail()
    {
        $api = $this->call->library('api');
        $api->require_method('POST');
        $db = $this->call->database();
        $body = $api->body();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $api->respond_error('Enter a valid email address.', 422);

        $account = $db->raw('SELECT u.user_id, u.username, u.is_verified, r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.email=? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC);
        if (!$account) $api->respond_error('No pending verification was found for this email. Start registration again.', 404);
        if ((int) $account['is_verified'] === 1) $api->respond_error('This account is already registered and verified. Please sign in.', 409);
        if (strtolower($account['role_name']) !== 'patient') $api->respond_error('This account cannot use patient email verification.', 403);
        $failedAttempts = (int) $db->raw(
            'SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND action=? AND table_name=? AND created_at>=DATE_SUB(NOW(), INTERVAL 30 MINUTE)',
            [(int) $account['user_id'], 'VERIFY_FAILED', 'email_verifications']
        )->fetchColumn();
        if ($failedAttempts >= 3) $api->respond_error('Too many incorrect codes. Wait 30 minutes before requesting a new verification code.', 429);

        $code = (string) random_int(100000, 999999);
        $emailResult = $this->sendVerificationEmail($email, $account['username'], $code);
        if (!$emailResult['sent']) $api->respond_error('We could not send a verification code. ' . $emailResult['error'], 502);
        $db->raw('UPDATE email_verifications SET is_used=1 WHERE user_id=? AND is_used=0', [(int) $account['user_id']]);
        $db->raw('INSERT INTO email_verifications (user_id, code, expires_at, is_used) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 0)', [(int) $account['user_id'], $code]);
        medicare_audit($db, (int) $account['user_id'], 'RESEND_VERIFICATION', 'email_verifications', null);
        $api->respond(['message' => 'A new verification code was sent. Check your inbox and spam folder.']);
    }

    public function requestPasswordReset()
    {
        $api = $this->call->library('api'); $api->require_method('POST'); $db = $this->call->database(); $body = $api->body(); $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $api->respond_error('Enter a valid email address.', 422);
        if (trim((string) getenv('EMAILJS_SERVICE_ID')) === '' || trim((string) getenv('EMAILJS_PASSWORD_RESET_TEMPLATE_ID')) === '' || trim((string) getenv('EMAILJS_PUBLIC_KEY')) === '' || !function_exists('curl_init')) {
            $api->respond_error('Password reset email is not configured. Please contact the clinic for help.', 503);
        }
        $user = $db->raw('SELECT user_id, username, is_verified, status FROM users WHERE email=? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $api->respond(['data' => [
                'status' => 'not_found',
                'message' => 'We could not find an account registered with this email. Check the email address or create a MediCare+ account first.',
            ]]);
        }
        if (!(int) $user['is_verified']) {
            $api->respond(['data' => [
                'status' => 'unverified',
                'message' => 'This account has not completed email verification. Verify your email before requesting a password reset.',
            ]]);
        }
        if (!in_array($user['status'], ['active', 'locked'], true)) {
            $api->respond(['data' => [
                'status' => 'inactive',
                'message' => 'This account is inactive. Please contact the clinic for help restoring access.',
            ]]);
        }

        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $db->raw('INSERT INTO password_resets (user_id, token, expires_at, is_used) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?)', [(int) $user['user_id'], $tokenHash, 0]);
        $emailResult = $this->sendPasswordResetEmail($email, (string) $user['username'], $token);
        if (!$emailResult['sent']) {
            $db->raw('UPDATE password_resets SET is_used=1 WHERE token=? AND is_used=0', [$tokenHash]);
            error_log('EmailJS password reset send failed: ' . $emailResult['error']);
            $api->respond_error('We could not send the password reset email. Please try again later or contact the clinic.', 502);
        }
        $db->raw('UPDATE password_resets SET is_used=1 WHERE user_id=? AND is_used=0 AND token<>?', [(int) $user['user_id'], $tokenHash]);
        $api->respond(['data' => [
            'status' => 'sent',
            'message' => 'We sent a password reset link to your email. Check your inbox and spam folder.',
        ]]);
    }

    /** Deliver a time-limited reset link without exposing the token to production clients. */
    private function sendPasswordResetEmail($email, $name, $token)
    {
        $serviceId = trim((string) getenv('EMAILJS_SERVICE_ID'));
        $templateId = trim((string) getenv('EMAILJS_PASSWORD_RESET_TEMPLATE_ID'));
        $publicKey = trim((string) getenv('EMAILJS_PUBLIC_KEY'));
        $privateKey = trim((string) getenv('EMAILJS_PRIVATE_KEY'));
        if ($serviceId === '' || $templateId === '' || $publicKey === '') return ['sent' => false, 'error' => 'Password reset EmailJS template is not configured.'];
        if (!function_exists('curl_init')) return ['sent' => false, 'error' => 'PHP cURL is not enabled.'];
        $baseUrl = rtrim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:5173'), '/');
        $resetUrl = $baseUrl . '/reset-password?token=' . rawurlencode($token);
        $caBundle = trim((string) getenv('EMAILJS_CA_BUNDLE'));
        if ($caBundle === '') $caBundle = APP_DIR . 'config/certs/mozilla-cacert.pem';
        elseif (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\\\/)/', $caBundle)) $caBundle = APP_DIR . ltrim($caBundle, '/\\\\');
        if (!is_file($caBundle) || !is_readable($caBundle)) return ['sent' => false, 'error' => 'Backend CA certificate bundle is missing or unreadable.'];
        $payload = json_encode([
            'service_id' => $serviceId,
            'template_id' => $templateId,
            'user_id' => $publicKey,
            'accessToken' => $privateKey,
            'template_params' => ['to_email' => $email, 'to_name' => $name, 'reset_url' => $resetUrl, 'app_name' => 'MediCare+', 'expires_in_minutes' => 30],
        ]);
        $curl = curl_init('https://api.emailjs.com/api/v1.0/email/send');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_CAINFO => $caBundle]);
        $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); $curlError = curl_error($curl); curl_close($curl);
        if ($response !== false && $status >= 200 && $status < 300) return ['sent' => true, 'error' => ''];
        $providerMessage = substr(trim(preg_replace('/\\s+/', ' ', strip_tags((string) $response))), 0, 240);
        return ['sent' => false, 'error' => 'EmailJS HTTP ' . $status . ($curlError !== '' ? ': ' . $curlError : '') . ($providerMessage !== '' ? ' ' . $providerMessage : '')];
    }

    public function validatePasswordReset()
    {
        $api = $this->call->library('api'); $api->require_method('POST'); $db = $this->call->database(); $body = $api->body(); $token = trim((string) ($body['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{48}$/i', $token)) $api->respond_error('Reset token is invalid or expired.', 422);
        $row = $db->raw('SELECT reset_id FROM password_resets WHERE token=? AND is_used=0 AND expires_at>NOW() LIMIT 1', [hash('sha256', $token)])->fetch(PDO::FETCH_ASSOC);
        if (!$row) $api->respond_error('Reset token is invalid or expired.', 422);
        $api->respond(['valid' => true]);
    }

    public function confirmPasswordReset()
    {
        $api = $this->call->library('api'); $api->require_method('POST'); $db = $this->call->database(); $body = $api->body(); $token = trim((string) ($body['token'] ?? '')); $password = (string) ($body['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/i', $token) || strlen($password) < 8) $api->respond_error('Use a valid reset token and a password of at least 8 characters.', 422);
        $row = $db->raw('SELECT reset_id, user_id FROM password_resets WHERE token=? AND is_used=0 AND expires_at>NOW() ORDER BY reset_id DESC LIMIT 1', [hash('sha256', $token)])->fetch(PDO::FETCH_ASSOC);
        if (!$row) $api->respond_error('Reset token is invalid or expired.', 422);
        $db->raw('UPDATE users SET password_hash=?, failed_login_attempts=0, status=IF(status=?, ?, status), locked_until=NULL WHERE user_id=?', [password_hash($password, PASSWORD_BCRYPT), 'locked', 'active', (int) $row['user_id']]);
        $db->raw('UPDATE password_resets SET is_used=1 WHERE reset_id=?', [(int) $row['reset_id']]); medicare_audit($db, (int) $row['user_id'], 'PASSWORD_RESET', 'users', (int) $row['user_id']);
        $api->respond(['message' => 'Password changed. You can sign in with your new password.']);
    }
}
