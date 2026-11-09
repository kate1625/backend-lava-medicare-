<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Clinic_operations extends Controller
{
    private function api() { return $this->call->library('api'); }
    private function db() { return $this->call->database(); }

    private function notifyPaymentInApp($db, int $paymentId, string $event, ?int $senderId): void
    {
        $payment = $db->raw(
            "SELECT p.appointment_id, p.amount, p.status, pt.user_id AS patient_user_id,
                    CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
                    s.service_name, a.appt_date, TIME_FORMAT(a.appt_time, '%H:%i') AS appt_time
             FROM payments p
             JOIN appointments a ON a.appointment_id=p.appointment_id
             JOIN patients pt ON pt.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             WHERE p.payment_id=? LIMIT 1",
            [$paymentId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new RuntimeException('Cannot create a payment notification without its payment record.');
        }

        $amount = 'PHP ' . number_format((float) $payment['amount'], 2);
        $appointment = $payment['service_name'] . ' on ' . $payment['appt_date'] . ' at ' . $payment['appt_time'];
        if ($event === 'submitted') {
            $patientTitle = 'Payment submitted';
            $patientMessage = 'Your ' . $amount . ' payment for ' . $appointment . ' is waiting for clinic verification.';
            $teamTitle = 'Payment awaiting verification';
            $teamMessage = $payment['patient_name'] . ' submitted ' . $amount . ' for ' . $appointment . '.';
        } elseif ($event === 'verified') {
            $patientTitle = 'Payment confirmed';
            $patientMessage = 'Your ' . $amount . ' payment for ' . $appointment . ' has been verified.';
            $teamTitle = '';
            $teamMessage = '';
        } elseif ($event === 'refunded') {
            $patientTitle = 'Payment refunded';
            $patientMessage = 'Your ' . $amount . ' payment for ' . $appointment . ' was marked as refunded. Contact the clinic if you need help.';
            $teamTitle = '';
            $teamMessage = '';
        } else {
            $patientTitle = 'Payment needs attention';
            $patientMessage = 'Your ' . $amount . ' payment for ' . $appointment . ' was not verified. Please contact the clinic for next steps.';
            $teamTitle = '';
            $teamMessage = '';
        }

        $eventKey = 'payment:' . $paymentId . ':' . $event;
        $model = $this->call->model('Notification_model');
        $model->createOnce(
            (int) $payment['patient_user_id'],
            $senderId,
            $patientTitle,
            $patientMessage,
            'payment',
            'patient',
            '/patient/payment',
            $eventKey
        );
        if ($event === 'submitted') {
            $model->createForRoles(
                ['Admin', 'Staff'],
                $senderId,
                $teamTitle,
                $teamMessage,
                'payment',
                '/staff/payments',
                $eventKey,
                $senderId
            );
        }
    }

    private function notifyPaymentVerified(array $payment, $db): array
    {
        $type = 'payment_verified_' . (int) $payment['payment_id'];
        if (medicare_email_was_sent(
            $db,
            (int) $payment['appointment_id'],
            (int) $payment['patient_id'],
            $type
        )) {
            return ['sent' => true, 'error' => ''];
        }

        $brevo = $this->call->library('brevo_mailer');
        $result = $brevo->sendPaymentVerified($payment);
        medicare_log_patient_email($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $type, 'Payment confirmed - MediCare+', $result);
        return $result;
    }

    private function paymentEmailDetails($db, int $paymentId, string $event): ?array
    {
        $payment = $db->raw(
            "SELECT p.payment_id, p.appointment_id, a.patient_id, p.amount, p.payment_plan, p.payment_percent,
                    p.reference_no, p.receipt_no, p.status, pt.email AS patient_email,
                    CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
                    s.service_name, s.price AS amount_due, pm.method_name,
                    CONCAT(a.appt_date, ' ', TIME_FORMAT(a.appt_time, '%H:%i')) AS appointment_label,
                    COALESCE((SELECT SUM(other.amount) FROM payments other
                              WHERE other.appointment_id=p.appointment_id AND other.status='verified'
                                AND other.payment_id<>p.payment_id), 0) AS verified_amount
             FROM payments p
             JOIN appointments a ON a.appointment_id=p.appointment_id
             JOIN patients pt ON pt.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id
             WHERE p.payment_id=? LIMIT 1",
            [$paymentId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            return null;
        }

        $payment['payment_plan_label'] = $payment['payment_plan'] === 'downpayment'
            ? (int) $payment['payment_percent'] . '% downpayment'
            : 'Full payment';
        $amountToCount = in_array($event, ['submitted', 'verified'], true) ? (float) $payment['amount'] : 0;
        $payment['balance_after_payment'] = max(
            (float) $payment['amount_due'] - (float) $payment['verified_amount'] - $amountToCount,
            0
        );
        return $payment;
    }

    private function notifyPaymentSubmittedEmails($db, int $paymentId): void
    {
        $payment = $this->paymentEmailDetails($db, $paymentId, 'submitted');
        if (!$payment) {
            error_log('MediCare+ payment email skipped: payment not found for #' . $paymentId);
            return;
        }

        $mailer = $this->call->library('brevo_mailer');
        $patientType = 'payment_submitted_' . $paymentId;
        if (!medicare_email_was_sent($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $patientType)) {
            $patientResult = $mailer->sendPatientPaymentSubmitted($payment);
            medicare_log_patient_email(
                $db,
                (int) $payment['appointment_id'],
                (int) $payment['patient_id'],
                $patientType,
                'Payment submitted for review - MediCare+',
                $patientResult
            );
        }

        $adminType = 'admin_pay_sub_' . $paymentId;
        if (medicare_email_was_sent($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $adminType)) {
            return;
        }
        $adminResult = $mailer->sendAdminPaymentAlert($payment, 'submitted');
        medicare_log_email(
            $db,
            (int) $payment['appointment_id'],
            (int) $payment['patient_id'],
            $adminType,
            'Payment needs verification - MediCare+',
            $adminResult,
            'admin'
        );
        if (!$adminResult['sent']) {
            error_log('MediCare+ admin payment email failed for #' . $paymentId . ': ' . $adminResult['error']);
        }
    }

    private function notifyAdminPaymentEmail($db, int $paymentId, string $event): void
    {
        $payment = $this->paymentEmailDetails($db, $paymentId, $event);
        if (!$payment) {
            error_log('MediCare+ admin payment email skipped: payment not found for #' . $paymentId);
            return;
        }

        $type = ($event === 'verified' ? 'admin_pay_ok_' : 'admin_pay_sub_') . $paymentId;
        if (medicare_email_was_sent($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $type)) {
            return;
        }
        $result = $this->call->library('brevo_mailer')->sendAdminPaymentAlert($payment, $event);
        medicare_log_email(
            $db,
            (int) $payment['appointment_id'],
            (int) $payment['patient_id'],
            $type,
            $event === 'verified' ? 'Payment received - MediCare+' : 'Payment needs verification - MediCare+',
            $result,
            'admin'
        );
        if (!$result['sent']) {
            error_log('MediCare+ admin payment email failed for #' . $paymentId . ': ' . $result['error']);
        }
    }

    private function notifyPaymentStatusEmail($db, int $paymentId, string $status): array
    {
        $payment = $this->paymentEmailDetails($db, $paymentId, $status);
        if (!$payment) {
            return ['sent' => false, 'error' => 'Payment details were not found.'];
        }

        $type = 'payment_' . $status . '_' . $paymentId;
        if (medicare_email_was_sent($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $type)) {
            return ['sent' => true, 'error' => ''];
        }

        $subject = 'Payment ' . $status . ' - MediCare+';
        $result = $this->call->library('brevo_mailer')->sendPatientPaymentStatus($payment, $status);
        medicare_log_patient_email($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $type, $subject, $result);
        return $result;
    }

    private function paymongoRequest($method, $path, $payload = null)
    {
        $secret = trim((string) getenv('PAYMONGO_SECRET_KEY'));
        if (strtolower(trim((string) getenv('PAYMONGO_MODE'))) !== 'test' || strpos($secret, 'sk_test_') !== 0) {
            throw new RuntimeException('PayMongo test checkout is not configured. Set PAYMONGO_MODE=test and add your PayMongo test secret key (sk_test_...) to the backend .env, then restart the backend.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for PayMongo.');
        }
        $caBundle = APP_DIR . 'config/certs/mozilla-cacert.pem';
        if (!is_file($caBundle) || !is_readable($caBundle)) {
            throw new RuntimeException('The backend CA certificate bundle is missing or unreadable.');
        }

        $curl = curl_init('https://api.paymongo.com/v1' . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CAINFO => $caBundle,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($secret . ':'),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            error_log('PayMongo connection failed: ' . $curlError);
            throw new RuntimeException('Could not connect to PayMongo.');
        }
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || !isset($decoded['data'])) {
            error_log('PayMongo returned an unsuccessful API response (HTTP ' . $status . ').');
            $detail = '';
            if (isset($decoded['errors'][0]) && is_array($decoded['errors'][0])) {
                $paymongoError = $decoded['errors'][0];
                $errorCode = trim((string) ($paymongoError['code'] ?? ''));
                $detail = trim((string) ($paymongoError['detail'] ?? $errorCode));
                if ($errorCode === 'maintenance') {
                    throw new RuntimeException('PayMongo rejected this Checkout API request with a maintenance response. The public status page may still show operational. Please try again later or use “Submit for clinic review” instead.');
                }
            }
            if ($status === 404) {
                throw new RuntimeException('PayMongo checkout is unavailable at the moment. Please use “Submit for clinic review” or contact the clinic.');
            }
            if ($status === 401 || $status === 403) {
                throw new RuntimeException('PayMongo test credentials were rejected. Please ask the clinic administrator to update the backend PayMongo test key.');
            }
            throw new RuntimeException($detail !== '' ? 'PayMongo: ' . $detail : 'PayMongo could not complete the request. Please use “Submit for clinic review” instead.');
        }
        return $decoded['data'];
    }

    private function paymongoPaymentTypes()
    {
        $configured = trim((string) getenv('PAYMONGO_PAYMENT_METHOD_TYPES'));
        $types = $configured === '' ? ['gcash', 'card'] : array_values(array_unique(array_map('trim', explode(',', strtolower($configured)))));
        $allowed = ['card', 'gcash', 'paymaya', 'grab_pay', 'qrph', 'dob', 'dob_ubp', 'brankas'];
        if (!$types || array_diff($types, $allowed)) throw new RuntimeException('Configure only supported PayMongo payment methods.');
        return $types;
    }

    private function paymongoCheckoutIsPaid(array $session): bool
    {
        $attributes = is_array($session['attributes'] ?? null) ? $session['attributes'] : [];
        if (in_array(strtolower((string) ($attributes['status'] ?? '')), ['paid', 'succeeded'], true)) {
            return true;
        }
        foreach (['payments', 'payment_intent'] as $key) {
            $value = $attributes[$key] ?? null;
            if (is_array($value)) {
                $items = array_is_list($value) ? $value : [$value];
                foreach ($items as $item) {
                    if (is_array($item) && in_array(strtolower((string) ($item['attributes']['status'] ?? $item['status'] ?? '')), ['paid', 'succeeded'], true)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function sendAppointmentNotification($id)
    {
        $api = $this->api();
        $api->require_method('POST');
        $db = $this->db();
        $user = medicare_require_role($db, $api, ['Staff', 'Admin']);

        $appointment = $db->raw(
            "SELECT a.appointment_id, a.patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, "
            . 'p.email AS patient_email, s.service_name, a.appt_date, a.appt_time, a.status '
            . 'FROM appointments a JOIN patients p ON p.patient_id=a.patient_id '
            . 'JOIN services s ON s.service_id=a.service_id WHERE a.appointment_id=? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) {
            $api->respond_error('Appointment not found.', 404);
        }
        if (!filter_var($appointment['patient_email'], FILTER_VALIDATE_EMAIL)) {
            $api->respond_error('The patient profile does not have a valid email address.', 422);
        }

        $status = str_replace('_', ' ', (string) $appointment['status']);
        $subject = 'Appointment ' . $status;
        $brevo = $this->call->library('brevo_mailer');
        $result = $brevo->sendAppointment($appointment);

        $db->raw(
            'INSERT INTO email_notifications (appointment_id, patient_id, type, subject, status, sent_at, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $appointment['appointment_id'],
                (int) $appointment['patient_id'],
                'appointment_update',
                $subject,
                $result['sent'] ? 'sent' : 'failed',
                $result['sent'] ? date('Y-m-d H:i:s') : null,
                $result['sent'] ? null : mb_substr($result['error'], 0, 255),
            ]
        );
        $notificationId = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        medicare_audit($db, (int) $user['user_id'], 'INSERT', 'email_notifications', $notificationId);

        if (!$result['sent']) {
            $api->respond_error($result['error'], 503);
        }
        $api->respond(['message' => 'Appointment email sent through Brevo and logged.']);
    }

    public function sendDueAppointmentReminders()
    {
        $api = $this->api();
        $api->require_method('POST');
        $configuredToken = trim((string) getenv('BREVO_REMINDER_TOKEN'));
        $requestToken = trim((string) ($_SERVER['HTTP_X_BREVO_REMINDER_TOKEN'] ?? ''));
        if ($configuredToken === '' || $requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
            $api->respond_error('Reminder endpoint is not authorized.', 401);
        }

        $db = $this->db();
        $appointments = $db->raw(
            "SELECT a.appointment_id, a.patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.email AS patient_email, s.service_name, a.appt_date, a.appt_time, a.status
             FROM appointments a
             JOIN patients p ON p.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             WHERE a.appt_date=CURDATE()
               AND TIMESTAMP(a.appt_date, a.appt_time)>=NOW()
               AND a.status IN ('pending','confirmed')
               AND p.email IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM email_notifications n
                   WHERE n.appointment_id=a.appointment_id AND n.type='appointment_reminder'
               )
             ORDER BY a.appt_time"
        )->fetchAll(PDO::FETCH_ASSOC);

        $brevo = $this->call->library('brevo_mailer');
        $sent = 0;
        $failed = 0;
        foreach ($appointments as $appointment) {
            $claim = $db->raw(
                "INSERT INTO email_notifications (appointment_id, patient_id, type, subject, status, scheduled_at)
                 SELECT ?, ?, 'appointment_reminder', 'Reminder: your MediCare+ appointment is today', 'pending', NOW()
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM email_notifications
                     WHERE appointment_id=? AND type='appointment_reminder'
                 )",
                [
                    (int) $appointment['appointment_id'],
                    (int) $appointment['patient_id'],
                    (int) $appointment['appointment_id'],
                ]
            );
            if ($claim->rowCount() !== 1) {
                continue;
            }

            $result = $brevo->sendAppointment($appointment, 'appointment_reminder');
            $db->raw(
                'UPDATE email_notifications SET status=?, sent_at=?, error_message=? WHERE appointment_id=? AND type=? AND status=?',
                [
                    $result['sent'] ? 'sent' : 'failed',
                    $result['sent'] ? date('Y-m-d H:i:s') : null,
                    $result['sent'] ? null : mb_substr($result['error'], 0, 255),
                    (int) $appointment['appointment_id'],
                    'appointment_reminder',
                    'pending',
                ]
            );
            if ($result['sent']) {
                $sent++;
            } else {
                $failed++;
                error_log('MediCare+ same-day reminder failed for appointment #' . $appointment['appointment_id'] . ': ' . $result['error']);
            }
        }

        $api->respond(['data' => ['found' => count($appointments), 'sent' => $sent, 'failed' => $failed]]);
    }

    public function createPaymongoCheckout()
    {
        $api = $this->api(); $api->require_method('POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient']); $body = $api->body();
        $appointmentId = filter_var($body['appointment_id'] ?? null, FILTER_VALIDATE_INT);
        $methodId = filter_var($body['payment_method_id'] ?? null, FILTER_VALIDATE_INT);
        $paymentPlan = ($body['payment_plan'] ?? 'full') === 'downpayment' ? 'downpayment' : 'full';
        $paymentPercent = $paymentPlan === 'downpayment' ? filter_var($body['payment_percent'] ?? null, FILTER_VALIDATE_INT) : 100;
        if (!$appointmentId || !$methodId) $api->respond_error('Choose an appointment and an online payment method.', 422);
        if (!in_array($paymentPercent, [50, 60, 70, 80, 100], true)) $api->respond_error('Choose a valid payment percentage.', 422);

        try {
            $types = $this->paymongoPaymentTypes();
        } catch (RuntimeException $error) {
            $api->respond_error($error->getMessage(), 503);
        }

        $method = $db->raw("SELECT payment_method_id FROM payment_methods WHERE payment_method_id=? AND is_active=1 AND type IN ('ewallet','card')", [$methodId])->fetch(PDO::FETCH_ASSOC);
        if (!$method) $api->respond_error('Choose an active card or e-wallet payment method.', 422);

        $db->transaction();
        $appointment = $db->raw("SELECT a.appointment_id, a.appt_date, a.appt_time, s.service_name, s.price FROM appointments a JOIN patients pt ON pt.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id WHERE a.appointment_id=? AND pt.user_id=? AND a.status NOT IN ('cancelled','no_show') FOR UPDATE", [$appointmentId, (int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) { $db->roll_back(); $api->respond_error('Appointment not found or unavailable for payment.', 404); }
        $verified = (float) $db->raw("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id=? AND status='verified'", [$appointmentId])->fetchColumn();
        $balance = max((float) $appointment['price'] - $verified, 0);
        $pending = (float) $db->raw("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id=? AND status='pending'", [$appointmentId])->fetchColumn();
        if ($pending > 0) { $db->roll_back(); $api->respond_error('A payment is already pending verification for this appointment.', 409); }
        if ($balance <= 0) { $db->roll_back(); $api->respond_error('This appointment is already fully paid.', 409); }
        $amount = $paymentPlan === 'downpayment' ? round((float) $appointment['price'] * $paymentPercent / 100, 2) : $balance;
        $amount = min($amount, $balance);

        $receipt = 'TD-' . strtoupper(bin2hex(random_bytes(5)));
        $db->raw("INSERT INTO payments (appointment_id, payment_method_id, amount, payment_plan, payment_percent, receipt_no, status, paid_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL)", [$appointmentId, $methodId, $amount, $paymentPlan, $paymentPercent, $receipt]);
        $paymentId = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $db->commit();

        $frontendUrl = rtrim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:5173'), '/');
        $returnUrl = $frontendUrl . '/patient/payment?paymongo=return&payment_id=' . $paymentId;
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name' => $appointment['service_name'],
                        'amount' => (int) round($amount * 100),
                        'currency' => 'PHP',
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => $types,
                    'description' => 'MediCare+ appointment #' . $appointmentId,
                    'success_url' => $returnUrl . '&result=success',
                    'cancel_url' => $returnUrl . '&result=cancel',
                    'metadata' => [
                        'medicare_payment_id' => (string) $paymentId,
                        'medicare_appointment_id' => (string) $appointmentId,
                    ],
                ],
            ],
        ];
        try {
            $session = $this->paymongoRequest('POST', '/checkout_sessions', $payload);
        } catch (RuntimeException $error) {
            $db->raw("UPDATE payments SET status='rejected' WHERE payment_id=? AND status='pending'", [$paymentId]);
            $api->respond_error($error->getMessage(), 502);
        }
        $sessionId = (string) ($session['id'] ?? '');
        $checkoutUrl = (string) ($session['attributes']['checkout_url'] ?? '');
        if ($sessionId === '' || $checkoutUrl === '') {
            $db->raw("UPDATE payments SET status='rejected' WHERE payment_id=? AND status='pending'", [$paymentId]);
            $api->respond_error('PayMongo did not return a valid checkout session.', 502);
        }
        $db->raw('UPDATE payments SET transaction_id=?, reference_no=? WHERE payment_id=?', ['paymongo:' . $sessionId, $sessionId, $paymentId]);
        medicare_audit($db, (int) $user['user_id'], 'INSERT', 'payments', $paymentId);
        $api->respond(['data' => ['payment_id' => $paymentId, 'checkout_url' => $checkoutUrl], 'message' => 'PayMongo test checkout created.'], 201);
    }

    public function confirmPaymongoPayment()
    {
        $api = $this->api(); $api->require_method('POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient', 'Staff', 'Admin']); $body = $api->body();
        $paymentId = filter_var($body['payment_id'] ?? null, FILTER_VALIDATE_INT);
        $verify = !empty($body['verify']);
        if (!$paymentId) $api->respond_error('A valid payment ID is required.', 422);
        if ($verify && $user['role_name'] === 'Patient') $api->respond_error('Only clinic staff can verify a completed PayMongo test payment.', 403);

        if ($user['role_name'] === 'Patient') {
            $payment = $db->raw("SELECT p.payment_id, p.status, p.transaction_id FROM payments p JOIN appointments a ON a.appointment_id=p.appointment_id JOIN patients pt ON pt.patient_id=a.patient_id WHERE p.payment_id=? AND pt.user_id=?", [$paymentId, (int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
        } else {
            $payment = $db->raw('SELECT payment_id, status, transaction_id FROM payments WHERE payment_id=?', [$paymentId])->fetch(PDO::FETCH_ASSOC);
        }
        if (!$payment) $api->respond_error('Payment not found.', 404);
        if (strpos((string) $payment['transaction_id'], 'paymongo:') !== 0) $api->respond_error('This payment is not a PayMongo checkout.', 422);
        if ($payment['status'] === 'verified') {
            $api->respond(['data' => ['status' => 'verified'], 'message' => 'PayMongo payment is verified.']);
        }
        if ($payment['status'] !== 'pending') $api->respond_error('This payment is no longer pending.', 409);

        $sessionId = substr((string) $payment['transaction_id'], strlen('paymongo:'));
        try {
            $session = $this->paymongoRequest('GET', '/checkout_sessions/' . rawurlencode($sessionId));
        } catch (RuntimeException $error) {
            $api->respond_error('Could not check the PayMongo payment yet. Please try again.', 502);
        }
        if (($session['id'] ?? '') !== $sessionId) $api->respond_error('PayMongo returned an unexpected checkout session.', 502);
        $status = strtolower((string) ($session['attributes']['status'] ?? ''));
        if ($this->paymongoCheckoutIsPaid($session)) {
            if (!$verify) {
                $this->notifyPaymentInApp($db, (int) $paymentId, 'submitted', (int) $user['user_id']);
                $this->notifyPaymentSubmittedEmails($db, (int) $paymentId);
                $api->respond(['data' => ['status' => 'paid'], 'message' => 'PayMongo test checkout is complete. It is waiting for clinic verification.']);
            }
            $db->transaction();
            $lockedPayment = $db->raw('SELECT status FROM payments WHERE payment_id=? LIMIT 1 FOR UPDATE', [$paymentId])->fetch(PDO::FETCH_ASSOC);
            if (!$lockedPayment) { $db->roll_back(); $api->respond_error('Payment not found.', 404); }
            if ($lockedPayment['status'] !== 'pending') {
                $db->roll_back();
                if ($lockedPayment['status'] === 'verified') {
                    $api->respond(['data' => ['status' => 'verified'], 'message' => 'PayMongo payment is verified.']);
                }
                $api->respond_error('This payment is no longer pending.', 409);
            }
            $paymentDetails = $db->raw(
                "SELECT p.payment_id, p.appointment_id, a.patient_id, p.amount, p.payment_plan, p.payment_percent, p.receipt_no,
                        CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name, pt.email AS patient_email,
                        s.service_name, s.price AS amount_due,
                        COALESCE((SELECT SUM(other.amount) FROM payments other WHERE other.appointment_id=p.appointment_id AND other.status='verified' AND other.payment_id<>p.payment_id), 0) AS verified_amount,
                        CONCAT(a.appt_date, ' ', TIME_FORMAT(a.appt_time, '%H:%i')) AS appointment_label
                 FROM payments p
                 JOIN appointments a ON a.appointment_id=p.appointment_id
                 JOIN patients pt ON pt.patient_id=a.patient_id
                 JOIN services s ON s.service_id=a.service_id
                 WHERE p.payment_id=? LIMIT 1",
                [$paymentId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$paymentDetails) { $db->roll_back(); $api->respond_error('Payment details were not found.', 404); }
            $db->raw("UPDATE payments SET status='verified', paid_at=NOW() WHERE payment_id=?", [$paymentId]);
            $this->notifyPaymentInApp($db, (int) $paymentId, 'verified', (int) $user['user_id']);
            medicare_audit($db, (int) $user['user_id'], 'UPDATE', 'payments', (int) $paymentId);
            $db->commit();
            $paymentDetails['payment_plan'] = $paymentDetails['payment_plan'] === 'downpayment'
                ? $paymentDetails['payment_percent'] . '% downpayment'
                : 'Full payment';
            $paymentDetails['balance_after_payment'] = max(
                (float) $paymentDetails['amount_due'] - (float) $paymentDetails['verified_amount'] - (float) $paymentDetails['amount'],
                0
            );
            $email = $this->notifyPaymentVerified($paymentDetails, $db);
            $this->notifyAdminPaymentEmail($db, (int) $paymentId, 'verified');
            $api->respond(['data' => ['status' => 'verified', 'email_sent' => $email['sent']], 'message' => $email['sent'] ? 'PayMongo test payment verified. Confirmation email sent.' : 'PayMongo test payment verified, but the confirmation email could not be sent.']);
        }
        if ($status === 'expired') {
            $db->transaction();
            $lockedPayment = $db->raw('SELECT status FROM payments WHERE payment_id=? LIMIT 1 FOR UPDATE', [$paymentId])->fetch(PDO::FETCH_ASSOC);
            if (!$lockedPayment) { $db->roll_back(); $api->respond_error('Payment not found.', 404); }
            if ($lockedPayment['status'] === 'verified') {
                $db->commit();
                $api->respond(['data' => ['status' => 'verified'], 'message' => 'PayMongo payment is verified.']);
            }
            $expiredPayment = $lockedPayment['status'] === 'pending';
            if ($expiredPayment) {
                $db->raw("UPDATE payments SET status='rejected' WHERE payment_id=?", [$paymentId]);
                $this->notifyPaymentInApp($db, (int) $paymentId, 'rejected', (int) $user['user_id']);
                medicare_audit($db, (int) $user['user_id'], 'UPDATE', 'payments', (int) $paymentId);
            }
            $db->commit();
            if ($expiredPayment) {
                $this->notifyPaymentStatusEmail($db, (int) $paymentId, 'rejected');
            }
            $api->respond(['data' => ['status' => 'expired'], 'message' => 'This PayMongo checkout expired. Start a new payment to try again.']);
        }
        $api->respond(['data' => ['status' => 'pending'], 'message' => 'PayMongo has not confirmed payment yet. You can check again shortly.']);
    }

    public function myPayments()
    {
        $api = $this->api(); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient']);
        $rows = $db->raw('SELECT p.payment_id, p.appointment_id, p.amount, p.payment_plan, p.payment_percent, p.reference_no, p.receipt_no, p.status, p.paid_at, pm.method_name, s.service_name, a.appt_date FROM payments p JOIN appointments a ON a.appointment_id=p.appointment_id JOIN patients pt ON pt.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id WHERE pt.user_id=? ORDER BY p.payment_id DESC', [(int) $user['user_id']])->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $rows]);
    }

    /** Appointment charges are derived from the live service price and verified payments. */
    public function patientCharges()
    {
        $api = $this->api(); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient']);
        $rows = $db->raw(
            "SELECT a.appointment_id,a.appt_date,a.appt_time,a.status AS appointment_status,s.service_name,s.price AS amount_due,COALESCE(v.verified_amount,0) AS amount_paid,GREATEST(s.price-COALESCE(v.verified_amount,0),0) AS balance_due,(SELECT p.status FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS latest_payment_status FROM appointments a JOIN patients pt ON pt.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id LEFT JOIN (SELECT appointment_id,SUM(amount) AS verified_amount FROM payments WHERE status='verified' GROUP BY appointment_id) v ON v.appointment_id=a.appointment_id WHERE pt.user_id=? AND a.status NOT IN ('cancelled','no_show') ORDER BY a.appt_date DESC,a.appt_time DESC",
            [(int) $user['user_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $rows]);
    }

    public function patientPaymentOverview()
    {
        $api = $this->api(); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient']);
        $userId = (int) $user['user_id'];
        $appointments = $db->raw(
            'SELECT a.appointment_id, a.status, s.service_name, s.price, a.appt_date FROM appointments a JOIN patients pt ON pt.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id WHERE pt.user_id=? ORDER BY a.appt_date DESC, a.appt_time DESC',
            [$userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $methods = $db->raw(
            'SELECT payment_method_id, method_name, type, account_name, account_no, instructions, is_maintenance FROM payment_methods WHERE is_active=1 ORDER BY method_name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $payments = $db->raw(
            'SELECT p.payment_id, p.appointment_id, p.status, p.amount, p.payment_plan, p.payment_percent FROM payments p JOIN appointments a ON a.appointment_id=p.appointment_id JOIN patients pt ON pt.patient_id=a.patient_id WHERE pt.user_id=?',
            [$userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $charges = $db->raw(
            "SELECT a.appointment_id,a.appt_date,a.appt_time,a.status AS appointment_status,s.service_name,s.price AS amount_due,COALESCE(v.verified_amount,0) AS amount_paid,GREATEST(s.price-COALESCE(v.verified_amount,0),0) AS balance_due,(SELECT p.status FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS latest_payment_status FROM appointments a JOIN patients pt ON pt.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id LEFT JOIN (SELECT appointment_id,SUM(amount) AS verified_amount FROM payments WHERE status='verified' GROUP BY appointment_id) v ON v.appointment_id=a.appointment_id WHERE pt.user_id=? AND a.status NOT IN ('cancelled','no_show') ORDER BY a.appt_date DESC,a.appt_time DESC",
            [$userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => [
            'appointments' => $appointments,
            'methods' => $methods,
            'payments' => $payments,
            'charges' => $charges,
        ]]);
    }

    public function activePaymentMethods()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Patient', 'Staff', 'Admin']);
        $rows = $db->raw('SELECT payment_method_id, method_name, type, account_name, account_no, instructions FROM payment_methods WHERE is_active=1 ORDER BY method_name')->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data' => $rows]);
    }

    public function submitPayment()
    {
        $api = $this->api(); $api->require_method('POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient']); $body = $api->body();
        $appointmentId = filter_var($body['appointment_id'] ?? null, FILTER_VALIDATE_INT); $methodId = filter_var($body['payment_method_id'] ?? null, FILTER_VALIDATE_INT); $reference = trim((string) ($body['reference_no'] ?? ''));
        $paymentPlan = ($body['payment_plan'] ?? 'full') === 'downpayment' ? 'downpayment' : 'full';
        $paymentPercent = $paymentPlan === 'downpayment' ? filter_var($body['payment_percent'] ?? null, FILTER_VALIDATE_INT) : 100;
        if (!$appointmentId || !$methodId || !in_array($paymentPercent, [50, 60, 70, 80, 100], true) || mb_strlen($reference) > 100) $api->respond_error('Choose a valid payment plan and payment method, then enter a valid reference.', 422);
        $method = $db->raw('SELECT payment_method_id FROM payment_methods WHERE payment_method_id=? AND is_active=1', [$methodId])->fetch(PDO::FETCH_ASSOC);
        if (!$method) $api->respond_error('Payment method not found or inactive.', 404);
        $db->transaction();
        $appointment = $db->raw("SELECT a.appointment_id, s.price FROM appointments a JOIN patients pt ON pt.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id WHERE a.appointment_id=? AND pt.user_id=? AND a.status NOT IN ('cancelled','no_show') FOR UPDATE", [$appointmentId, (int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) { $db->roll_back(); $api->respond_error('Appointment not found or unavailable for payment.', 404); }
        $verified = (float) $db->raw("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id=? AND status='verified'", [$appointmentId])->fetchColumn();
        $balance = max((float) $appointment['price'] - $verified, 0);
        $pending = (float) $db->raw("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE appointment_id=? AND status='pending'", [$appointmentId])->fetchColumn();
        if ($pending > 0) { $db->roll_back(); $api->respond_error('A payment is already pending verification for this appointment.', 409); }
        if ($balance <= 0) { $db->roll_back(); $api->respond_error('This appointment is already fully paid.', 409); }
        $amount = $paymentPlan === 'downpayment' ? round((float) $appointment['price'] * $paymentPercent / 100, 2) : $balance;
        $amount = min($amount, $balance);
        $db->raw('INSERT INTO payments (appointment_id, payment_method_id, amount, payment_plan, payment_percent, reference_no, receipt_no, status, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())', [$appointmentId, $methodId, $amount, $paymentPlan, $paymentPercent, $reference ?: null, 'TD-' . strtoupper(bin2hex(random_bytes(5))), 'pending']);
        $id = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $this->notifyPaymentInApp($db, $id, 'submitted', (int) $user['user_id']);
        $db->commit(); medicare_audit($db, (int) $user['user_id'], 'INSERT', 'payments', $id);
        $this->notifyPaymentSubmittedEmails($db, $id);
        $api->respond(['data' => ['payment_id' => $id], 'message' => 'Payment submitted for clinic verification.'], 201);
    }

    public function receipt($id)
    {
        $api = $this->api(); $db = $this->db(); $user = medicare_require_role($db, $api, ['Patient']);
        $row = $db->raw('SELECT p.payment_id, p.receipt_no, p.amount, p.payment_plan, p.payment_percent, p.reference_no, p.status, p.paid_at, pm.method_name, pm.account_name, pm.account_no, a.appt_date, a.appt_time, s.service_name, pt.first_name, pt.last_name FROM payments p JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id JOIN appointments a ON a.appointment_id=p.appointment_id JOIN services s ON s.service_id=a.service_id JOIN patients pt ON pt.patient_id=a.patient_id WHERE p.payment_id=? AND pt.user_id=?', [(int) $id, (int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$row) $api->respond_error('Receipt not found.', 404);
        $api->respond(['data' => $row]);
    }

    public function pendingPayments()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Staff', 'Admin']);
        $rows = $db->raw("SELECT p.payment_id, p.appointment_id, p.amount, p.payment_plan, p.payment_percent, s.price AS amount_due, GREATEST(s.price-COALESCE(v.verified_amount,0)-p.amount,0) AS balance_due, p.reference_no, p.receipt_no, p.status, p.paid_at, pm.method_name, s.service_name, a.appt_date, CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name, CASE WHEN p.transaction_id LIKE 'paymongo:%' THEN 'paymongo' ELSE 'clinic' END AS verification_source FROM payments p JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id JOIN appointments a ON a.appointment_id=p.appointment_id JOIN services s ON s.service_id=a.service_id JOIN patients pt ON pt.patient_id=a.patient_id LEFT JOIN (SELECT appointment_id,SUM(amount) AS verified_amount FROM payments WHERE status='verified' GROUP BY appointment_id) v ON v.appointment_id=p.appointment_id WHERE p.status='pending' ORDER BY p.paid_at")->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $rows]);
    }

    public function paymentHistory()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Staff', 'Admin']);
        $rows = $db->raw("SELECT p.payment_id, p.appointment_id, p.amount, p.payment_plan, p.payment_percent,
            p.reference_no, p.receipt_no, p.status, p.paid_at, pm.method_name, s.service_name,
            a.appt_date, CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
            CASE WHEN p.transaction_id LIKE 'paymongo:%' THEN 'paymongo' ELSE 'clinic' END AS verification_source
            FROM payments p
            JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id
            JOIN appointments a ON a.appointment_id=p.appointment_id
            JOIN services s ON s.service_id=a.service_id
            JOIN patients pt ON pt.patient_id=a.patient_id
            WHERE p.status IN ('verified','rejected')
            ORDER BY p.paid_at DESC, p.payment_id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $rows]);
    }

    public function paymentStatus($id)
    {
        $api = $this->api(); $api->require_method('POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Staff', 'Admin']); $body = $api->body(); $status = $body['status'] ?? '';
        if (!in_array($status, ['verified', 'rejected', 'refunded'], true)) $api->respond_error('Select verified, rejected, or refunded.', 422);
        $db->transaction();
        $payment = $db->raw(
            "SELECT p.payment_id, p.appointment_id, a.patient_id, pt.user_id AS patient_user_id, p.status, p.transaction_id, p.amount, p.payment_plan, p.payment_percent, p.receipt_no,
                    CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name, pt.email AS patient_email,
                    s.service_name, s.price AS amount_due, pm.method_name,
                    COALESCE((SELECT SUM(other.amount) FROM payments other WHERE other.appointment_id=p.appointment_id AND other.status='verified' AND other.payment_id<>p.payment_id), 0) AS verified_amount,
                    CONCAT(a.appt_date, ' ', TIME_FORMAT(a.appt_time, '%H:%i')) AS appointment_label
             FROM payments p
             JOIN appointments a ON a.appointment_id=p.appointment_id
             JOIN patients pt ON pt.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id
             WHERE p.payment_id=? LIMIT 1 FOR UPDATE",
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$payment) { $db->roll_back(); $api->respond_error('Payment not found.', 404); }
        if ($status === 'verified' && strpos((string) $payment['transaction_id'], 'paymongo:') === 0) {
            $db->roll_back();
            $api->respond_error('PayMongo payments must be verified by PayMongo, not manually.', 409);
        }
        $previousStatus = (string) $payment['status'];
        $payment['balance_after_payment'] = max(
            (float) $payment['amount_due'] - (float) $payment['verified_amount']
                - ($status === 'verified' ? (float) $payment['amount'] : 0),
            0
        );
        $payment['payment_plan_label'] = $payment['payment_plan'] === 'downpayment'
            ? (int) $payment['payment_percent'] . '% downpayment'
            : 'Full payment';
        $db->raw(
            'UPDATE payments SET status=?, paid_at=CASE WHEN ? = ? THEN COALESCE(paid_at, NOW()) ELSE paid_at END WHERE payment_id=?',
            [$status, $status, 'verified', (int) $id]
        );
        if ($previousStatus !== $status) {
            $this->notifyPaymentInApp($db, (int) $id, $status, (int) $user['user_id']);
        }
        $db->commit();
        medicare_audit($db, (int) $user['user_id'], 'UPDATE', 'payments', (int) $id);
        if ($status !== 'verified') {
            $changed = $previousStatus !== $status;
            $emailType = 'payment_' . $status . '_' . (int) $id;
            $email = $changed
                ? $this->notifyPaymentStatusEmail($db, (int) $id, $status)
                : ['sent' => medicare_email_was_sent($db, (int) $payment['appointment_id'], (int) $payment['patient_id'], $emailType)];
            $api->respond([
                'data' => ['email_sent' => $email['sent']],
                'message' => !$changed
                    ? 'Payment status was already set.'
                    : ($email['sent'] ? 'Payment status updated and patient email sent.' : 'Payment status updated, but patient email could not be sent.'),
            ]);
        }
        $payment['payment_percent'] = $payment['payment_percent'] ?? null;
        $payment['payment_plan'] = $payment['payment_plan'] === 'downpayment'
            ? $payment['payment_percent'] . '% downpayment'
            : 'Full payment';
        $email = $this->notifyPaymentVerified($payment, $db);
        if ($previousStatus !== 'verified') {
            $this->notifyAdminPaymentEmail($db, (int) $id, 'verified');
        }
        $api->respond([
            'data' => ['email_sent' => $email['sent']],
            'message' => $email['sent'] ? 'Payment verified and confirmation email sent.' : 'Payment verified, but the confirmation email could not be sent.',
        ]);
    }

    public function treatments()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Staff', 'Admin']);
        $appointmentId = filter_var($_GET['appointment_id'] ?? null, FILTER_VALIDATE_INT);
        $sql = "SELECT tr.record_id, tr.appointment_id, tr.patient_id, tr.dentist_id, tr.tooth_no, tr.diagnosis, tr.treatment_done, tr.notes, tr.created_at, CONCAT(p.first_name, ' ', p.last_name) AS patient_name FROM treatment_records tr JOIN patients p ON p.patient_id=tr.patient_id";
        $rows = $db->raw($sql . ($appointmentId ? ' WHERE tr.appointment_id=?' : '') . ' ORDER BY tr.created_at DESC LIMIT 500', $appointmentId ? [$appointmentId] : [])->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data' => $rows]);
    }

    public function addTreatment()
    {
        $api = $this->api(); $api->require_method('POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Staff', 'Admin']); $body = $api->body();
        $appointmentId = filter_var($body['appointment_id'] ?? null, FILTER_VALIDATE_INT); $dentistId = filter_var($body['dentist_id'] ?? null, FILTER_VALIDATE_INT); $tooth = trim((string) ($body['tooth_no'] ?? '')); $diagnosis = trim((string) ($body['diagnosis'] ?? '')); $treatment = trim((string) ($body['treatment_done'] ?? '')); $notes = trim((string) ($body['notes'] ?? ''));
        if (!$appointmentId || !$dentistId || (!$diagnosis && !$treatment) || mb_strlen($diagnosis)>5000 || mb_strlen($treatment)>5000 || mb_strlen($notes)>5000 || ($tooth!=='' && !preg_match('/^(1[1-8]|2[1-8]|3[1-8]|4[1-8])$/',$tooth))) $api->respond_error('Choose an appointment and dentist, enter a diagnosis or treatment, and use a valid FDI tooth number.', 422);
        $appointment = $db->raw('SELECT patient_id,dentist_id FROM appointments WHERE appointment_id=?', [$appointmentId])->fetch(PDO::FETCH_ASSOC); $dentist = $db->raw('SELECT dentist_id FROM dentists WHERE dentist_id=?', [$dentistId])->fetch(PDO::FETCH_ASSOC);
        if (!$appointment || !$dentist) $api->respond_error('Appointment or dentist not found.', 404);
        if ($appointment['dentist_id'] && (int)$appointment['dentist_id'] !== (int)$dentistId) $api->respond_error('Choose the dentist assigned to this appointment.', 422);
        $db->raw('INSERT INTO treatment_records (appointment_id, patient_id, dentist_id, tooth_no, diagnosis, treatment_done, notes) VALUES (?, ?, ?, ?, ?, ?, ?)', [$appointmentId, $appointment['patient_id'], $dentistId, $tooth ?: null, $diagnosis ?: null, $treatment ?: null, $notes ?: null]);
        $id = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(); medicare_audit($db, (int) $user['user_id'], 'INSERT', 'treatment_records', $id); $api->respond(['data' => ['record_id' => $id]], 201);
    }

    public function notificationLog()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Staff', 'Admin']);
        $rows = $db->raw("SELECT n.notification_id, n.appointment_id, n.patient_id, n.type, n.subject, n.status, n.scheduled_at, n.sent_at, n.error_message, CASE WHEN LEFT(n.type, 6)='admin_' THEN 'Clinic Admin' ELSE CONCAT(p.first_name, ' ', p.last_name) END AS patient_name FROM email_notifications n JOIN patients p ON p.patient_id=n.patient_id ORDER BY n.notification_id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data' => $rows]);
    }

    public function saveNotificationResult()
    {
        $api = $this->api(); $api->require_method('POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Staff', 'Admin']); $body = $api->body();
        $appointmentId = filter_var($body['appointment_id'] ?? null, FILTER_VALIDATE_INT); $type = trim((string) ($body['type'] ?? 'appointment_update')); $subject = trim((string) ($body['subject'] ?? '')); $status = $body['status'] ?? ''; $error = trim((string) ($body['error_message'] ?? ''));
        if (!$appointmentId || !in_array($status, ['sent', 'failed'], true) || mb_strlen($type) > 30 || mb_strlen($subject) > 150 || mb_strlen($error) > 255) $api->respond_error('Notification result is invalid.', 422);
        $appointment = $db->raw('SELECT patient_id FROM appointments WHERE appointment_id=?', [$appointmentId])->fetch(PDO::FETCH_ASSOC); if (!$appointment) $api->respond_error('Appointment not found.', 404);
        $db->raw('INSERT INTO email_notifications (appointment_id, patient_id, type, subject, status, sent_at, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)', [$appointmentId, $appointment['patient_id'], $type, $subject ?: null, $status, $status === 'sent' ? date('Y-m-d H:i:s') : null, $status === 'failed' ? ($error ?: 'Email provider reported a failure.') : null]);
        $id = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(); medicare_audit($db, (int) $user['user_id'], 'INSERT', 'email_notifications', $id); $api->respond(['data' => ['notification_id' => $id]], 201);
    }

    public function services()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Admin']);
        $rows = $db->raw('SELECT service_id, service_name, category, description, price, duration_min, is_active FROM services ORDER BY service_name')->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data' => $rows]);
    }

    public function saveService($id = null)
    {
        $api = $this->api(); $api->require_method($id ? 'PUT' : 'POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Admin']); $body = $api->body();
        $name = trim((string) ($body['service_name'] ?? '')); $category = trim((string) ($body['category'] ?? '')); $description = trim((string) ($body['description'] ?? '')); $price = filter_var($body['price'] ?? null, FILTER_VALIDATE_FLOAT); $duration = filter_var($body['duration_min'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($category) > 50 || mb_strlen($description) > 5000 || $price === false || $price < 0 || $duration === false || $duration < 5 || $duration > 600) $api->respond_error('Enter a service name, valid category, non-negative price, and duration from 5 to 600 minutes.', 422);
        $active = !empty($body['is_active']) ? 1 : 0;
        if ($id) { $exists = $db->raw('SELECT service_id FROM services WHERE service_id=?', [(int) $id])->fetch(PDO::FETCH_ASSOC); if (!$exists) $api->respond_error('Service not found.', 404); $db->raw('UPDATE services SET service_name=?, category=?, description=?, price=?, duration_min=?, is_active=? WHERE service_id=?', [$name, $category ?: null, $description ?: null, $price, $duration, $active, (int) $id]); $recordId = (int) $id; $action = 'UPDATE'; }
        else { $db->raw('INSERT INTO services (service_name, category, description, price, duration_min, is_active) VALUES (?, ?, ?, ?, ?, ?)', [$name, $category ?: null, $description ?: null, $price, $duration, $active]); $recordId = (int) $db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(); $action = 'INSERT'; }
        medicare_audit($db, (int) $user['user_id'], $action, 'services', $recordId); $api->respond(['data' => ['service_id' => $recordId]]);
    }

    public function archiveService($id)
    {
        $api = $this->api(); $api->require_method('DELETE'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Admin']); $exists = $db->raw('SELECT service_id FROM services WHERE service_id=?', [(int) $id])->fetch(PDO::FETCH_ASSOC); if (!$exists) $api->respond_error('Service not found.', 404);
        $db->raw('UPDATE services SET is_active=0 WHERE service_id=?', [(int) $id]); medicare_audit($db, (int) $user['user_id'], 'ARCHIVE', 'services', (int) $id); $api->respond(['message' => 'Service deactivated.']);
    }

    public function paymentMethods()
    {
        $api = $this->api(); $db = $this->db(); medicare_require_role($db, $api, ['Admin']); $rows = $db->raw('SELECT payment_method_id, method_name, type, account_name, account_no, instructions, is_active FROM payment_methods ORDER BY method_name')->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data' => $rows]);
    }

    public function savePaymentMethod($id = null)
    {
        $api = $this->api(); $api->require_method($id ? 'PUT' : 'POST'); $db = $this->db(); $user = medicare_require_role($db, $api, ['Admin']); $body = $api->body(); $name = trim((string) ($body['method_name'] ?? '')); $type = $body['type'] ?? ''; $accountName = trim((string) ($body['account_name'] ?? '')); $accountNo = trim((string) ($body['account_no'] ?? '')); $instructions = trim((string) ($body['instructions'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50 || !in_array($type, ['cash','ewallet','card','bank'], true) || mb_strlen($accountName) > 100 || mb_strlen($accountNo) > 50 || mb_strlen($instructions) > 5000) $api->respond_error('Enter a valid method name, type, and account details.', 422); $active = !empty($body['is_active']) ? 1 : 0;
        if ($id) { $db->raw('UPDATE payment_methods SET method_name=?, type=?, account_name=?, account_no=?, instructions=?, is_active=? WHERE payment_method_id=?', [$name,$type,$accountName ?: null,$accountNo ?: null,$instructions ?: null,$active,(int)$id]); $recordId=(int)$id; $action='UPDATE'; }
        else { $db->raw('INSERT INTO payment_methods (method_name,type,account_name,account_no,instructions,is_active) VALUES (?,?,?,?,?,?)',[$name,$type,$accountName ?: null,$accountNo ?: null,$instructions ?: null,$active]); $recordId=(int)$db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(); $action='INSERT'; }
        medicare_audit($db,(int)$user['user_id'],$action,'payment_methods',$recordId); $api->respond(['data'=>['payment_method_id'=>$recordId]]);
    }

    public function dentists()
    {
        $api=$this->api(); $db=$this->db(); medicare_require_role($db,$api,['Staff','Admin']); $rows=$db->raw('SELECT d.dentist_id,d.specialization,d.license_no,u.username,u.email,s.schedule_id,s.day_of_week,s.start_time,s.end_time FROM dentists d JOIN users u ON u.user_id=d.user_id LEFT JOIN dentist_schedules s ON s.dentist_id=d.dentist_id ORDER BY u.username,s.day_of_week,s.start_time')->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data'=>$rows]);
    }

    public function saveSchedule()
    {
        $api=$this->api(); $api->require_method('POST'); $db=$this->db(); $user=medicare_require_role($db,$api,['Admin']); $body=$api->body(); $dentist=filter_var($body['dentist_id']??null,FILTER_VALIDATE_INT); $day=filter_var($body['day_of_week']??null,FILTER_VALIDATE_INT); $start=$body['start_time']??''; $end=$body['end_time']??'';
        if (!$dentist || $day===false || $day<0 || $day>6 || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$end) || $start >= $end) $api->respond_error('Enter a dentist, weekday 0–6, and valid ordered start/end times.',422);
        $exists=$db->raw('SELECT dentist_id FROM dentists WHERE dentist_id=?',[$dentist])->fetch(PDO::FETCH_ASSOC); if(!$exists)$api->respond_error('Dentist not found.',404);
        $overlap=$db->raw('SELECT schedule_id FROM dentist_schedules WHERE dentist_id=? AND day_of_week=? AND start_time<? AND end_time>? LIMIT 1',[$dentist,$day,$end,$start])->fetch(PDO::FETCH_ASSOC);
        if($overlap)$api->respond_error('This schedule overlaps an existing availability window.',409);
        $db->raw('INSERT INTO dentist_schedules (dentist_id,day_of_week,start_time,end_time) VALUES (?,?,?,?)',[$dentist,$day,$start,$end]); $id=(int)$db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(); medicare_audit($db,(int)$user['user_id'],'INSERT','dentist_schedules',$id); $api->respond(['data'=>['schedule_id'=>$id]]);
    }

    public function deleteSchedule($id)
    {
        $api=$this->api(); $api->require_method('DELETE'); $db=$this->db(); $user=medicare_require_role($db,$api,['Admin']); $db->raw('DELETE FROM dentist_schedules WHERE schedule_id=?',[(int)$id]); medicare_audit($db,(int)$user['user_id'],'DELETE','dentist_schedules',(int)$id); $api->respond(['message'=>'Schedule removed.']);
    }

    public function users()
    {
        $api=$this->api(); $db=$this->db(); medicare_require_role($db,$api,['Admin']);
        $rows=$db->raw(
            "SELECT u.user_id,u.username,u.email,r.role_name,u.is_verified,u.status,u.last_login,
                    u.failed_login_attempts,u.created_at,
                    COALESCE(activity.appointment_count,0) AS appointment_count,
                    COALESCE(payments.amount_paid,0) AS amount_paid,
                    COALESCE(activity.balance_due,0) AS balance_due,
                    COALESCE(pt.is_archived,0) AS is_archived,
                    activity.last_activity,
                    CASE
                        WHEN pt.patient_id IS NULL THEN 'not_applicable'
                        WHEN COALESCE(activity.appointment_count,0)=0 THEN 'no_appointments'
                        WHEN COALESCE(payments.has_pending,0)=1 THEN 'pending_verification'
                        WHEN COALESCE(activity.balance_due,0)>0 THEN 'unpaid'
                        ELSE 'paid'
                    END AS patient_payment_status
             FROM users u
             JOIN roles r ON r.role_id=u.role_id
             LEFT JOIN patients pt ON pt.user_id=u.user_id
             LEFT JOIN (
                 SELECT a.patient_id,COUNT(*) AS appointment_count,
                        MAX(CONCAT(a.appt_date,' ',a.appt_time)) AS last_activity,
                        SUM(GREATEST(s.price-COALESCE(verified.amount_paid,0),0)) AS balance_due
                 FROM appointments a
                 JOIN services s ON s.service_id=a.service_id
                 LEFT JOIN (
                     SELECT appointment_id,SUM(amount) AS amount_paid
                     FROM payments WHERE status='verified' GROUP BY appointment_id
                 ) verified ON verified.appointment_id=a.appointment_id
                 WHERE a.status NOT IN ('cancelled','no_show')
                 GROUP BY a.patient_id
             ) activity ON activity.patient_id=pt.patient_id
             LEFT JOIN (
                 SELECT a.patient_id,
                        SUM(CASE WHEN p.status='verified' THEN p.amount ELSE 0 END) AS amount_paid,
                        MAX(CASE WHEN p.status='pending' THEN 1 ELSE 0 END) AS has_pending
                 FROM payments p
                 JOIN appointments a ON a.appointment_id=p.appointment_id
                 GROUP BY a.patient_id
             ) payments ON payments.patient_id=pt.patient_id
             ORDER BY u.created_at DESC
             LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data'=>$rows]);
    }

    public function userStatus($id)
    {
        $api=$this->api(); $api->require_method('POST'); $db=$this->db(); $user=medicare_require_role($db,$api,['Admin']); $body=$api->body(); $status=$body['status']??''; if(!in_array($status,['active','inactive','locked'],true))$api->respond_error('Choose active, inactive, or locked.',422); if((int)$id===(int)$user['user_id'])$api->respond_error('You cannot change your own account status.',422); $db->raw("UPDATE users SET status=?,failed_login_attempts=IF(?='active',0,failed_login_attempts),locked_until=CASE WHEN ?='locked' THEN DATE_ADD(NOW(), INTERVAL 100 YEAR) WHEN ?='active' THEN NULL ELSE locked_until END WHERE user_id=?",[$status,$status,$status,$status,(int)$id]); medicare_audit($db,(int)$user['user_id'],'UPDATE','users',(int)$id); $api->respond(['message'=>'Account status updated.']);
    }

    public function auditLogs()
    {
        $api=$this->api(); $db=$this->db(); medicare_require_role($db,$api,['Admin']); $rows=$db->raw('SELECT l.log_id,l.user_id,u.username,l.action,l.table_name,l.record_id,l.ip_address,l.created_at FROM audit_logs l LEFT JOIN users u ON u.user_id=l.user_id ORDER BY l.created_at DESC,l.log_id DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data'=>$rows]);
    }

    public function staffDashboard()
    {
        $api=$this->api(); $db=$this->db(); medicare_require_role($db,$api,['Staff','Admin']);
        $counts=$db->raw("SELECT COUNT(*) AS total, SUM(status='pending') AS pending, SUM(status='confirmed') AS confirmed, SUM(status='completed') AS completed FROM appointments")->fetch(PDO::FETCH_ASSOC);
        $patients=(int)$db->raw('SELECT COUNT(*) FROM patients WHERE is_archived=0')->fetchColumn();
        $upcoming=$db->raw("SELECT a.appointment_id,a.appt_date,a.appt_time,a.status,s.service_name,CONCAT(p.first_name,' ',p.last_name) AS patient_name FROM appointments a JOIN patients p ON p.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id WHERE a.appt_date>=CURRENT_DATE AND a.status IN ('pending','confirmed') ORDER BY a.appt_date,a.appt_time LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        $byStatus=$db->raw('SELECT status,COUNT(*) AS total FROM appointments GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
        $byDay=$db->raw("SELECT DAYNAME(appt_date) AS day_name,COUNT(*) AS total FROM appointments WHERE appt_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY) AND appt_date <= CURRENT_DATE GROUP BY appt_date ORDER BY appt_date")->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data'=>['patients'=>$patients,'appointments'=>$counts,'upcoming'=>$upcoming,'appointments_by_status'=>$byStatus,'appointments_by_day'=>$byDay]]);
    }

    public function adminDashboard()
    {
        $api=$this->api(); $db=$this->db(); medicare_require_role($db,$api,['Admin']);
        $users=(int)$db->raw('SELECT COUNT(*) FROM users')->fetchColumn(); $services=(int)$db->raw('SELECT COUNT(*) FROM services WHERE is_active=1')->fetchColumn(); $pendingPayments=(int)$db->raw("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
        $patients=(int)$db->raw('SELECT COUNT(*) FROM patients WHERE is_archived=0')->fetchColumn();
        $dentists=(int)$db->raw("SELECT COUNT(*) FROM dentists d JOIN users u ON u.user_id=d.user_id WHERE u.status='active'")->fetchColumn();
        $outstandingBalance=(float)$db->raw("SELECT COALESCE(SUM(GREATEST(s.price-COALESCE(v.verified_amount,0),0)),0) FROM appointments a JOIN services s ON s.service_id=a.service_id LEFT JOIN (SELECT appointment_id,SUM(amount) AS verified_amount FROM payments WHERE status='verified' GROUP BY appointment_id) v ON v.appointment_id=a.appointment_id WHERE a.status NOT IN ('cancelled','no_show')")->fetchColumn();
        $appointments=$db->raw("SELECT status,COUNT(*) AS total FROM appointments GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        $revenue=$db->raw("SELECT DATE_FORMAT(paid_at,'%Y-%m') AS month,SUM(amount) AS total FROM payments WHERE status='verified' AND paid_at >= DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH) GROUP BY month ORDER BY month")->fetchAll(PDO::FETCH_ASSOC);
        $byService=$db->raw('SELECT s.service_name,COUNT(a.appointment_id) AS total FROM services s LEFT JOIN appointments a ON a.service_id=s.service_id GROUP BY s.service_id,s.service_name ORDER BY total DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data'=>['users'=>$users,'patients'=>$patients,'dentists'=>$dentists,'active_services'=>$services,'pending_payments'=>$pendingPayments,'outstanding_balance'=>$outstandingBalance,'appointments_by_status'=>$appointments,'verified_revenue_by_month'=>$revenue,'appointments_by_service'=>$byService]]);
    }
}
