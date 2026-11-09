<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Brevo_mailer
{
    private function loginUrl(): string
    {
        return rtrim((string) (getenv('FRONTEND_URL') ?: 'http://localhost:5174'), '/') . '/login';
    }

    private function loginButton(string $label): string
    {
        $url = htmlspecialchars($this->loginUrl(), ENT_QUOTES, 'UTF-8');
        return '<p style="margin:26px 0"><a href="' . $url . '" style="display:inline-block;padding:13px 22px;border-radius:8px;background:#32866e;color:#fff;text-decoration:none;font-weight:700">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></p>';
    }

    private function adminEmail(): string
    {
        return trim((string) (getenv('ADMIN_NOTIFICATION_EMAIL') ?: 'medicareplus.dental666@gmail.com'));
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlContent): array
    {
        $apiKey = trim((string) getenv('BREVO_API_KEY'));
        $senderEmail = trim((string) getenv('BREVO_SENDER_EMAIL'));
        $senderName = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'MediCare+'));
        if ($apiKey === '' || $senderEmail === '') {
            return ['sent' => false, 'error' => 'Brevo is not configured. Set BREVO_API_KEY and BREVO_SENDER_EMAIL in the backend .env.'];
        }
        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'Brevo sender or recipient email is invalid.'];
        }
        if (!function_exists('curl_init')) {
            return ['sent' => false, 'error' => 'PHP cURL is not enabled.'];
        }

        $caBundle = trim((string) getenv('BREVO_CA_BUNDLE'));
        if ($caBundle === '') {
            $caBundle = APP_DIR . 'config/certs/mozilla-cacert.pem';
        } elseif (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $caBundle)) {
            $caBundle = APP_DIR . ltrim($caBundle, '/\\');
        }
        if (!is_file($caBundle) || !is_readable($caBundle)) {
            return ['sent' => false, 'error' => 'The backend CA certificate bundle is missing or unreadable.'];
        }

        $payload = json_encode([
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [['email' => $toEmail, 'name' => $toName]],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ]);
        if ($payload === false) {
            return ['sent' => false, 'error' => 'Could not encode the Brevo message.'];
        }

        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json',
            ],
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

        $providerMessage = substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $response))), 0, 200);
        error_log('Brevo appointment notification failed. HTTP ' . $status . ($curlError !== '' ? ': ' . $curlError : '') . ($providerMessage !== '' ? ' Response: ' . $providerMessage : ''));
        return ['sent' => false, 'error' => 'Brevo rejected the message or is unreachable. Check the Brevo sender, API key, and backend logs.'];
    }

    public function sendAppointment(array $appointment, string $type = 'appointment_update'): array
    {
        $patientName = htmlspecialchars((string) $appointment['patient_name'], ENT_QUOTES, 'UTF-8');
        $serviceName = htmlspecialchars((string) $appointment['service_name'], ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string) $appointment['appt_date'], ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars(substr((string) $appointment['appt_time'], 0, 5), ENT_QUOTES, 'UTF-8');
        $status = str_replace('_', ' ', (string) $appointment['status']);
        $rescheduleReason = htmlspecialchars((string) ($appointment['reschedule_reason'] ?? ''), ENT_QUOTES, 'UTF-8');
        $subject = $type === 'appointment_reminder'
            ? 'Reminder: your MediCare+ appointment is today'
            : ($type === 'appointment_created' ? 'MediCare+ appointment request received' : 'MediCare+ appointment ' . $status);
        $heading = $type === 'appointment_reminder'
            ? 'Your appointment is today'
            : ($type === 'appointment_created' ? 'We received your appointment request' : 'Appointment update');
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238">'
            . '<h2>' . $heading . '</h2><p>Hello ' . $patientName . ',</p>'
            . '<p>Your MediCare+ appointment details:</p><ul>'
            . '<li><strong>Service:</strong> ' . $serviceName . '</li>'
            . '<li><strong>Date:</strong> ' . $date . '</li>'
            . '<li><strong>Time:</strong> ' . $time . '</li>'
            . '<li><strong>Status:</strong> ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</li>'
            . ($type === 'appointment_rescheduled' && $rescheduleReason !== '' ? '<li><strong>Reason for rescheduling:</strong> ' . $rescheduleReason . '</li>' : '')
            . '</ul><p>Please arrive a little early and bring any required information. '
            . 'If you need to change this appointment, contact the clinic.</p>'
            . $this->loginButton('Open your patient portal')
            . '<p>Thank you,<br>MediCare+</p></body></html>';

        return $this->send((string) $appointment['patient_email'], (string) $appointment['patient_name'], $subject, $html);
    }

    public function sendAdminAppointmentBooked(array $appointment): array
    {
        $patient = htmlspecialchars((string) $appointment['patient_name'], ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($appointment['patient_email'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) $appointment['service_name'], ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string) $appointment['appt_date'], ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars(substr((string) $appointment['appt_time'], 0, 5), ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars(str_replace('_', ' ', (string) $appointment['status']), ENT_QUOTES, 'UTF-8');
        $subject = 'New appointment booking - MediCare+';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238">'
            . '<h2>New appointment booking</h2><p>A new appointment was added to the clinic schedule.</p>'
            . '<ul><li><strong>Patient:</strong> ' . $patient . '</li><li><strong>Patient email:</strong> ' . $email . '</li>'
            . '<li><strong>Service:</strong> ' . $service . '</li><li><strong>Date and time:</strong> ' . $date . ' at ' . $time . '</li>'
            . '<li><strong>Status:</strong> ' . $status . '</li><li><strong>Appointment:</strong> #' . (int) $appointment['appointment_id'] . '</li></ul>'
            . $this->loginButton('Sign in to review booking')
            . '<p>MediCare+ clinic notifications</p></body></html>';

        return $this->send($this->adminEmail(), 'MediCare+ Admin', $subject, $html);
    }

    public function sendAdminAppointmentCancelled(array $appointment): array
    {
        $patient = htmlspecialchars((string) $appointment['patient_name'], ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) $appointment['service_name'], ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars((string) $appointment['appt_date'], ENT_QUOTES, 'UTF-8');
        $time = htmlspecialchars(substr((string) $appointment['appt_time'], 0, 5), ENT_QUOTES, 'UTF-8');
        $reason = htmlspecialchars((string) ($appointment['cancel_reason'] ?? 'No reason provided'), ENT_QUOTES, 'UTF-8');
        $subject = 'Patient cancelled an appointment - MediCare+';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238">'
            . '<h2>Appointment cancelled by patient</h2><ul><li><strong>Patient:</strong> ' . $patient . '</li>'
            . '<li><strong>Service:</strong> ' . $service . '</li><li><strong>Date and time:</strong> ' . $date . ' at ' . $time . '</li>'
            . '<li><strong>Reason:</strong> ' . $reason . '</li><li><strong>Appointment:</strong> #' . (int) $appointment['appointment_id'] . '</li></ul>'
            . $this->loginButton('Review appointment')
            . '<p>MediCare+ clinic notifications</p></body></html>';

        return $this->send($this->adminEmail(), 'MediCare+ Admin', $subject, $html);
    }

    public function sendPatientPaymentSubmitted(array $payment): array
    {
        $name = htmlspecialchars((string) $payment['patient_name'], ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) $payment['service_name'], ENT_QUOTES, 'UTF-8');
        $appointment = htmlspecialchars((string) $payment['appointment_label'], ENT_QUOTES, 'UTF-8');
        $method = htmlspecialchars((string) $payment['method_name'], ENT_QUOTES, 'UTF-8');
        $plan = htmlspecialchars((string) $payment['payment_plan_label'], ENT_QUOTES, 'UTF-8');
        $subject = 'Payment submitted for review - MediCare+';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238"><h2>Payment submitted</h2>'
            . '<p>Hello ' . $name . ', we received your payment details. The clinic will review and verify this payment.</p>'
            . '<ul><li><strong>Service:</strong> ' . $service . '</li><li><strong>Appointment:</strong> ' . $appointment . '</li>'
            . '<li><strong>Payment method:</strong> ' . $method . '</li><li><strong>Plan:</strong> ' . $plan . '</li>'
            . '<li><strong>Amount submitted:</strong> PHP ' . number_format((float) $payment['amount'], 2) . '</li>'
            . '<li><strong>Service total:</strong> PHP ' . number_format((float) $payment['amount_due'], 2) . '</li>'
            . '<li><strong>Previously verified:</strong> PHP ' . number_format((float) $payment['verified_amount'], 2) . '</li>'
            . '<li><strong>Estimated remaining after verification:</strong> PHP ' . number_format((float) $payment['balance_after_payment'], 2) . '</li></ul>'
            . $this->loginButton('View your payment')
            . '<p>If you did not submit this payment, please contact the clinic.</p></body></html>';

        return $this->send((string) $payment['patient_email'], (string) $payment['patient_name'], $subject, $html);
    }

    public function sendAdminPaymentAlert(array $payment, string $event = 'submitted'): array
    {
        $patient = htmlspecialchars((string) $payment['patient_name'], ENT_QUOTES, 'UTF-8');
        $patientEmail = htmlspecialchars((string) ($payment['patient_email'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) $payment['service_name'], ENT_QUOTES, 'UTF-8');
        $appointment = htmlspecialchars((string) $payment['appointment_label'], ENT_QUOTES, 'UTF-8');
        $method = htmlspecialchars((string) ($payment['method_name'] ?? 'Online payment'), ENT_QUOTES, 'UTF-8');
        $plan = htmlspecialchars((string) $payment['payment_plan_label'], ENT_QUOTES, 'UTF-8');
        $isVerified = $event === 'verified';
        $subject = $isVerified ? 'Payment received - MediCare+' : 'Payment needs verification - MediCare+';
        $heading = $isVerified ? 'Payment received successfully' : 'New payment submitted for verification';
        $balanceLabel = $isVerified ? 'Balance remaining' : 'Estimated balance after verification';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238"><h2>' . $heading . '</h2>'
            . '<ul><li><strong>Patient:</strong> ' . $patient . '</li><li><strong>Patient email:</strong> ' . $patientEmail . '</li>'
            . '<li><strong>Service:</strong> ' . $service . '</li><li><strong>Appointment:</strong> ' . $appointment . '</li>'
            . '<li><strong>Payment method:</strong> ' . $method . '</li><li><strong>Plan:</strong> ' . $plan . '</li>'
            . '<li><strong>Amount ' . ($isVerified ? 'received' : 'submitted') . ':</strong> PHP ' . number_format((float) $payment['amount'], 2) . '</li>'
            . '<li><strong>Service total:</strong> PHP ' . number_format((float) $payment['amount_due'], 2) . '</li>'
            . '<li><strong>Previously verified:</strong> PHP ' . number_format((float) $payment['verified_amount'], 2) . '</li>'
            . '<li><strong>' . $balanceLabel . ':</strong> PHP ' . number_format((float) $payment['balance_after_payment'], 2) . '</li>'
            . '<li><strong>Receipt/reference:</strong> ' . htmlspecialchars((string) ($payment['receipt_no'] ?: $payment['reference_no'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8') . '</li></ul>'
            . $this->loginButton($isVerified ? 'Open admin workspace' : 'Review payment')
            . '<p>MediCare+ clinic notifications</p></body></html>';

        return $this->send($this->adminEmail(), 'MediCare+ Admin', $subject, $html);
    }

    public function sendPatientPaymentStatus(array $payment, string $status): array
    {
        $name = htmlspecialchars((string) $payment['patient_name'], ENT_QUOTES, 'UTF-8');
        $service = htmlspecialchars((string) $payment['service_name'], ENT_QUOTES, 'UTF-8');
        $statusLabel = htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8');
        $subject = 'Payment ' . $status . ' - MediCare+';
        $message = $status === 'rejected'
            ? 'The clinic could not verify this payment. Please contact the clinic or submit corrected payment details.'
            : 'This payment was marked as refunded. Contact the clinic if you have questions about the refund.';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238"><h2>Payment ' . $statusLabel . '</h2>'
            . '<p>Hello ' . $name . ',</p><p>' . $message . '</p><ul><li><strong>Service:</strong> ' . $service . '</li>'
            . '<li><strong>Plan:</strong> ' . htmlspecialchars((string) $payment['payment_plan_label'], ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Amount:</strong> PHP ' . number_format((float) $payment['amount'], 2) . '</li>'
            . '<li><strong>Current balance:</strong> PHP ' . number_format((float) $payment['balance_after_payment'], 2) . '</li></ul>'
            . $this->loginButton('Open your patient portal')
            . '<p>MediCare+ Dental Clinic</p></body></html>';

        return $this->send((string) $payment['patient_email'], (string) $payment['patient_name'], $subject, $html);
    }

    public function sendPrescription(array $prescription): array
    {
        $name = htmlspecialchars((string) $prescription['patient_name'], ENT_QUOTES, 'UTF-8');
        $subject = 'New prescription instructions - MediCare+';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#203238"><h2>New prescription instructions</h2>'
            . '<p>Hello ' . $name . ', your care team added a prescription to your patient portal.</p>'
            . '<p>Sign in securely to review your instructions. For your privacy, prescription details are not included in this email.</p>'
            . $this->loginButton('View your prescription')
            . '<p>MediCare+ Dental Clinic</p></body></html>';

        return $this->send((string) $prescription['patient_email'], (string) $prescription['patient_name'], $subject, $html);
    }

    public function sendPaymentVerified(array $payment): array
    {
        $patientName = htmlspecialchars((string) $payment['patient_name'], ENT_QUOTES, 'UTF-8');
        $receiptNo = htmlspecialchars((string) $payment['receipt_no'], ENT_QUOTES, 'UTF-8');
        $serviceName = htmlspecialchars((string) $payment['service_name'], ENT_QUOTES, 'UTF-8');
        $appointment = htmlspecialchars((string) $payment['appointment_label'], ENT_QUOTES, 'UTF-8');
        $amount = number_format((float) $payment['amount'], 2);
        $plan = htmlspecialchars((string) $payment['payment_plan'], ENT_QUOTES, 'UTF-8');
        $subject = 'Payment confirmed - MediCare+';
        $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f3f8f6;font-family:Arial,sans-serif;color:#203238">'
            . '<div style="max-width:620px;margin:auto;background:#ffffff;border:1px solid #dce9e3;border-radius:16px;overflow:hidden">'
            . '<div style="padding:26px 30px;background:#194a4e;color:#ffffff"><div style="font-size:22px;font-weight:700">MediCare<span style="color:#91d2b0">+</span></div>'
            . '<div style="margin-top:7px;font-size:12px;letter-spacing:1px;color:#cce9dd">PAYMENT CONFIRMATION</div></div>'
            . '<div style="padding:30px"><h2 style="margin:0 0 14px;color:#254650">Your payment has been confirmed</h2>'
            . '<p>Hello ' . $patientName . ',</p><p>The clinic has confirmed that your payment was received and recorded successfully.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:22px 0;font-size:14px">'
            . '<tr><td style="padding:10px 0;color:#6b8085">Receipt</td><td style="padding:10px 0;text-align:right;font-weight:700">' . $receiptNo . '</td></tr>'
            . '<tr><td style="padding:10px 0;color:#6b8085;border-top:1px solid #e5eeea">Service</td><td style="padding:10px 0;text-align:right;border-top:1px solid #e5eeea">' . $serviceName . '</td></tr>'
            . '<tr><td style="padding:10px 0;color:#6b8085;border-top:1px solid #e5eeea">Appointment</td><td style="padding:10px 0;text-align:right;border-top:1px solid #e5eeea">' . $appointment . '</td></tr>'
            . '<tr><td style="padding:10px 0;color:#6b8085;border-top:1px solid #e5eeea">Payment plan</td><td style="padding:10px 0;text-align:right;border-top:1px solid #e5eeea">' . $plan . '</td></tr>'
            . '<tr><td style="padding:14px 0 0;color:#257158;font-weight:700;border-top:1px solid #e5eeea">Amount confirmed</td><td style="padding:14px 0 0;text-align:right;color:#257158;font-size:20px;font-weight:700;border-top:1px solid #e5eeea">PHP ' . $amount . '</td></tr>'
            . '<tr><td style="padding:10px 0;color:#6b8085;border-top:1px solid #e5eeea">Balance remaining</td><td style="padding:10px 0;text-align:right;border-top:1px solid #e5eeea">PHP ' . number_format((float) ($payment['balance_after_payment'] ?? 0), 2) . '</td></tr>'
            . '</table><p style="color:#63777d">You can view your receipt anytime in the MediCare+ patient portal.</p>'
            . $this->loginButton('Open your patient portal')
            . '<p style="margin-bottom:0">Thank you,<br><strong>Tooth Doctor Dental Clinic</strong></p></div></div></body></html>';

        return $this->send((string) $payment['patient_email'], (string) $payment['patient_name'], $subject, $html);
    }
}
