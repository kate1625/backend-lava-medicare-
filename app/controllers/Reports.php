<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Reports extends Controller
{
    private function reportDateFilter($api): array
    {
        $from = trim((string) ($_GET['from'] ?? ''));
        $to = trim((string) ($_GET['to'] ?? ''));
        foreach (['from' => $from, 'to' => $to] as $label => $date) {
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $api->respond_error('Choose valid report start and end dates.', 422);
            }
            if ($date !== '') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $errors = DateTimeImmutable::getLastErrors();
                if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d') !== $date) {
                    $api->respond_error('Choose valid report start and end dates.', 422);
                }
            }
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            $api->respond_error('The report start date must be on or before the end date.', 422);
        }
        return [$from, $to];
    }

    private function dateConditions(string $column, string $from, string $to): array
    {
        $conditions = [];
        $parameters = [];
        if ($from !== '') {
            $conditions[] = $column . ' >= ?';
            $parameters[] = $from;
        }
        if ($to !== '') {
            $conditions[] = $column . ' < DATE_ADD(?, INTERVAL 1 DAY)';
            $parameters[] = $to;
        }
        return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $parameters];
    }

    public function index()
    {
        $api = $this->call->library('api');
        $database = $this->call->database();
        $user = medicare_require_role($database, $api, ['Admin']);
        [$from, $to] = $this->reportDateFilter($api);

        $appointments = $database->raw(
            "SELECT status, COUNT(*) AS total FROM appointments"
            . $this->dateConditions('appt_date', $from, $to)[0]
            . ' GROUP BY status',
            $this->dateConditions('appt_date', $from, $to)[1]
        )->fetchAll(PDO::FETCH_ASSOC);
        $revenue = $database->raw(
            "SELECT DATE_FORMAT(COALESCE(paid_at, CURRENT_TIMESTAMP), '%Y-%m') AS month, SUM(amount) AS total "
            . 'FROM payments WHERE status=\'verified\''
            . ($from !== '' ? ' AND COALESCE(paid_at, CURRENT_TIMESTAMP) >= ?' : '')
            . ($to !== '' ? ' AND COALESCE(paid_at, CURRENT_TIMESTAMP) < DATE_ADD(?, INTERVAL 1 DAY)' : '')
            . " GROUP BY DATE_FORMAT(COALESCE(paid_at, CURRENT_TIMESTAMP), '%Y-%m') ORDER BY month DESC",
            array_values(array_filter([$from ?: null, $to ?: null], static fn($value) => $value !== null))
        )->fetchAll(PDO::FETCH_ASSOC);
        $services = $database->raw(
            'SELECT s.service_name, COUNT(a.appointment_id) AS total FROM services s '
            . 'LEFT JOIN appointments a ON a.service_id=s.service_id'
            . ($from !== '' ? ' AND a.appt_date >= ?' : '')
            . ($to !== '' ? ' AND a.appt_date < DATE_ADD(?, INTERVAL 1 DAY)' : '')
            . ' GROUP BY s.service_id, s.service_name ORDER BY total DESC, s.service_name',
            array_values(array_filter([$from ?: null, $to ?: null], static fn($value) => $value !== null))
        )->fetchAll(PDO::FETCH_ASSOC);

        [$patientFilter, $patientParameters] = $this->dateConditions('p.created_at', $from, $to);
        [$appointmentFilter, $appointmentParameters] = $this->dateConditions('a.appt_date', $from, $to);
        [$prescriptionFilter, $prescriptionParameters] = $this->dateConditions('r.created_at', $from, $to);
        [$treatmentFilter, $treatmentParameters] = $this->dateConditions('tr.created_at', $from, $to);
        [$paymentFilter, $paymentParameters] = $this->dateConditions('COALESCE(pay.paid_at, a.appt_date)', $from, $to);
        [$emailFilter, $emailParameters] = $this->dateConditions('COALESCE(n.sent_at, n.scheduled_at)', $from, $to);
        [$auditFilter, $auditParameters] = $this->dateConditions('l.created_at', $from, $to);
        [$userFilter, $userParameters] = $this->dateConditions('u.created_at', $from, $to);

        $patientRows = $database->raw(
            "SELECT p.patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.email, p.contact_no,
                    p.birth_date, p.sex, p.is_archived, p.created_at
             FROM patients p" . $patientFilter . ' ORDER BY p.created_at DESC, p.patient_id DESC LIMIT 1000',
            $patientParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $appointmentRows = $database->raw(
            "SELECT a.appointment_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    s.service_name, COALESCE(u.username, 'Unassigned') AS dentist,
                    a.appt_date, TIME_FORMAT(a.appt_time, '%H:%i') AS appt_time,
                    a.status, a.created_at
             FROM appointments a
             JOIN patients p ON p.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             LEFT JOIN dentists d ON d.dentist_id=a.dentist_id
             LEFT JOIN users u ON u.user_id=d.user_id"
            . $appointmentFilter . ' ORDER BY a.appt_date DESC, a.appt_time DESC LIMIT 1000',
            $appointmentParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $scheduleRows = $database->raw(
            "SELECT u.username AS dentist, d.specialization, ds.day_of_week,
                    TIME_FORMAT(ds.start_time, '%H:%i') AS start_time,
                    TIME_FORMAT(ds.end_time, '%H:%i') AS end_time
             FROM dentist_schedules ds
             JOIN dentists d ON d.dentist_id=ds.dentist_id
             JOIN users u ON u.user_id=d.user_id
             ORDER BY u.username, ds.day_of_week, ds.start_time"
        )->fetchAll(PDO::FETCH_ASSOC);
        $prescriptionRows = $database->raw(
            "SELECT r.prescription_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    r.appointment_id, a.appt_date, a.status AS appointment_status,
                    r.medication, r.directions, r.status, u.username AS prescribed_by, r.created_at
             FROM prescriptions r
             JOIN patients p ON p.patient_id=r.patient_id
             JOIN users u ON u.user_id=r.created_by
             LEFT JOIN appointments a ON a.appointment_id=r.appointment_id"
            . $prescriptionFilter . ' ORDER BY r.created_at DESC LIMIT 1000',
            $prescriptionParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $treatmentRows = $database->raw(
            "SELECT tr.record_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    tr.tooth_no, tr.diagnosis, tr.treatment_done, tr.notes,
                    u.username AS dentist, tr.created_at
             FROM treatment_records tr
             JOIN patients p ON p.patient_id=tr.patient_id
             JOIN dentists d ON d.dentist_id=tr.dentist_id
             JOIN users u ON u.user_id=d.user_id"
            . $treatmentFilter . ' ORDER BY tr.created_at DESC LIMIT 1000',
            $treatmentParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $paymentRows = $database->raw(
            "SELECT pay.payment_id, pay.receipt_no, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    s.service_name, pm.method_name, pay.amount, pay.status, pay.paid_at
             FROM payments pay
             JOIN appointments a ON a.appointment_id=pay.appointment_id
             JOIN patients p ON p.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             JOIN payment_methods pm ON pm.payment_method_id=pay.payment_method_id"
            . $paymentFilter . ' ORDER BY pay.paid_at DESC, pay.payment_id DESC LIMIT 1000',
            $paymentParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $serviceRows = $database->raw(
            'SELECT service_name, category, price, duration_min, is_active FROM services ORDER BY is_active DESC, service_name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $emailRows = $database->raw(
            "SELECT n.notification_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    n.type, n.subject, n.status, n.scheduled_at, n.sent_at, n.error_message
             FROM email_notifications n
             JOIN patients p ON p.patient_id=n.patient_id"
            . $emailFilter . ' ORDER BY COALESCE(n.sent_at, n.scheduled_at) DESC, n.notification_id DESC LIMIT 1000',
            $emailParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $auditRows = $database->raw(
            "SELECT l.log_id, COALESCE(u.username, 'System/anonymous') AS username,
                    l.action, l.table_name, l.record_id, l.ip_address, l.created_at
             FROM audit_logs l
             LEFT JOIN users u ON u.user_id=l.user_id"
            . $auditFilter . ' ORDER BY l.created_at DESC, l.log_id DESC LIMIT 1000',
            $auditParameters
        )->fetchAll(PDO::FETCH_ASSOC);
        $userRows = $database->raw(
            "SELECT u.user_id, u.username, u.email, r.role_name, u.is_verified, u.status, u.created_at
             FROM users u
             JOIN roles r ON r.role_id=u.role_id"
            . $userFilter . ' ORDER BY u.created_at DESC, u.user_id DESC LIMIT 1000',
            $userParameters
        )->fetchAll(PDO::FETCH_ASSOC);

        medicare_audit($database, (int) $user['user_id'], 'READ', 'appointments', null);
        $api->respond(['data' => [
            'filters' => ['from' => $from, 'to' => $to],
            'appointments_by_status' => $appointments,
            'verified_revenue_by_month' => array_reverse($revenue),
            'appointments_by_service' => $services,
            'patients' => $patientRows,
            'appointments' => $appointmentRows,
            'schedules' => $scheduleRows,
            'prescriptions' => $prescriptionRows,
            'treatments' => $treatmentRows,
            'payments' => $paymentRows,
            'services' => $serviceRows,
            'email_notifications' => $emailRows,
            'audit_logs' => $auditRows,
            'users' => $userRows,
        ]]);
    }
}
