<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Appointments extends Controller
{
    private function api() { return $this->call->library('api'); }
    private function database() { return $this->call->database(); }

    private function notifyAppointmentPatient($database, int $appointmentId, string $eventKey, string $title, string $message, ?int $senderId): void
    {
        $patient = $database->raw(
            'SELECT p.user_id FROM appointments a JOIN patients p ON p.patient_id=a.patient_id WHERE a.appointment_id=? LIMIT 1',
            [$appointmentId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$patient) {
            throw new RuntimeException('Cannot create an appointment notification without its patient account.');
        }

        $this->call->model('Notification_model')->createOnce(
            (int) $patient['user_id'],
            $senderId,
            $title,
            $message,
            'appointment',
            'patient',
            '/patient/appointments',
            $eventKey
        );
    }

    private function notifyAppointmentTeam(string $eventKey, string $title, string $message, ?int $senderId, ?int $excludeUserId = null): void
    {
        $this->call->model('Notification_model')->createForRoles(
            ['Admin', 'Staff'],
            $senderId,
            $title,
            $message,
            'appointment',
            '/staff/appointments',
            $eventKey,
            $excludeUserId
        );
    }

    private function notifyPatient($database, int $appointmentId, string $type): void
    {
        $appointment = $database->raw(
            "SELECT a.appointment_id, a.patient_id, a.reschedule_reason, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, "
            . 'p.email AS patient_email, s.service_name, a.appt_date, a.appt_time, a.status '
            . 'FROM appointments a JOIN patients p ON p.patient_id=a.patient_id '
            . 'JOIN services s ON s.service_id=a.service_id WHERE a.appointment_id=? LIMIT 1',
            [$appointmentId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$appointment || !filter_var($appointment['patient_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            error_log('MediCare+ appointment notification skipped: appointment or patient email is missing for #' . $appointmentId);
            return;
        }

        $result = $this->call->library('brevo_mailer')->sendAppointment($appointment, $type);
        $subject = $type === 'appointment_reminder'
            ? 'Reminder: your MediCare+ appointment is today'
            : ($type === 'appointment_created' ? 'MediCare+ appointment request received' : 'Appointment ' . str_replace('_', ' ', (string) $appointment['status']));
        medicare_log_patient_email($database, $appointmentId, (int) $appointment['patient_id'], $type, $subject, $result);
    }

    private function notifyAdminAppointment($database, int $appointmentId, string $event): void
    {
        $appointment = $database->raw(
            "SELECT a.appointment_id, a.patient_id, a.status, a.cancel_reason, a.appt_date, a.appt_time,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.email AS patient_email,
                    s.service_name
             FROM appointments a
             JOIN patients p ON p.patient_id=a.patient_id
             JOIN services s ON s.service_id=a.service_id
             WHERE a.appointment_id=? LIMIT 1",
            [$appointmentId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) {
            error_log('MediCare+ admin email skipped: appointment not found for #' . $appointmentId);
            return;
        }

        $type = 'admin_' . $event . '_' . $appointmentId;
        if (medicare_email_was_sent($database, $appointmentId, (int) $appointment['patient_id'], $type)) {
            return;
        }

        $mailer = $this->call->library('brevo_mailer');
        if ($event === 'cancelled') {
            $result = $mailer->sendAdminAppointmentCancelled($appointment);
        } else {
            $result = $mailer->sendAdminAppointmentBooked($appointment);
        }
        medicare_log_email(
            $database,
            $appointmentId,
            (int) $appointment['patient_id'],
            $type,
            $event === 'cancelled' ? 'Patient cancelled an appointment - MediCare+' : 'New appointment booking - MediCare+',
            $result,
            'admin'
        );
        if (!$result['sent']) {
            error_log('MediCare+ admin appointment email failed for #' . $appointmentId . ': ' . $result['error']);
        }
    }

    private function dateIsValid(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed && ($errors === false || (!$errors['warning_count'] && !$errors['error_count'])) && $parsed->format('Y-m-d') === $date;
    }

    private function slotIsInFuture(string $date, string $time): bool
    {
        return $date > date('Y-m-d') || ($date === date('Y-m-d') && $time > date('H:i:s'));
    }

    private function appointmentRows($database, array $user): array
    {
        $base = "SELECT a.appointment_id, a.patient_id, a.service_id, a.dentist_id, a.appt_date, a.appt_time, a.end_time, a.status, a.remarks, a.cancel_reason, a.reschedule_reason, a.created_at, CONCAT(pt.first_name, ' ', pt.last_name) AS patient, pt.email AS patient_email, s.service_name, s.price, CONCAT(du.username) AS dentist, "
            . "(SELECT p.payment_id FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_id, "
            . "(SELECT p.status FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_status, "
            . "(SELECT p.amount FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_amount, "
            . "(SELECT pm.method_name FROM payments p JOIN payment_methods pm ON pm.payment_method_id=p.payment_method_id WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_method, "
            . "(SELECT p.reference_no FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_reference, "
            . "(SELECT p.transaction_id FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_transaction, "
            . "(SELECT p.receipt_no FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS receipt_no "
            . ",(SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.appointment_id=a.appointment_id AND p.status='verified') AS verified_amount "
            . "FROM appointments a JOIN services s ON s.service_id=a.service_id JOIN patients pt ON pt.patient_id=a.patient_id LEFT JOIN dentists d ON d.dentist_id=a.dentist_id LEFT JOIN users du ON du.user_id=d.user_id";
        if ($user['role_name'] === 'Patient') {
            $statement = $database->raw($base . ' WHERE pt.user_id=? ORDER BY a.appt_date DESC, a.appt_time DESC', [(int) $user['user_id']]);
        } else {
            $statement = $database->raw($base . ' ORDER BY a.appt_date DESC, a.appt_time DESC');
        }
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function slotIsAvailable($database, int $dentistId, string $date, string $start, int $duration, int $excludeAppointmentId = 0): bool
    {
        $day = (int) (new DateTimeImmutable($date))->format('w');
        $end = (new DateTimeImmutable($date . ' ' . $start))->modify('+' . $duration . ' minutes')->format('H:i:s');
        $schedule = $database->raw(
            "SELECT ds.schedule_id FROM dentist_schedules ds
             WHERE ds.dentist_id=? AND ds.day_of_week=? AND ds.start_time<=? AND ds.end_time>=?
               AND NOT EXISTS (
                   SELECT 1 FROM appointments a JOIN services s ON s.service_id=a.service_id
                   WHERE a.dentist_id=ds.dentist_id AND a.appt_date=?
                     AND a.status NOT IN ('cancelled','no_show') AND a.appointment_id<>?
                     AND a.appt_time<?
                     AND COALESCE(a.end_time, ADDTIME(a.appt_time, SEC_TO_TIME(GREATEST(s.duration_min,15) * 60)))>?
               )
             LIMIT 1 FOR UPDATE",
            [$dentistId, $day, $start, $end, $date, $excludeAppointmentId, $end, $start]
        )->fetch(PDO::FETCH_ASSOC);
        return (bool) $schedule;
    }

    public function availability()
    {
        $api = $this->api();
        $database = $this->database();
        medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $date = trim((string) ($_GET['date'] ?? ''));
        $dentistId = filter_var($_GET['dentist_id'] ?? null, FILTER_VALIDATE_INT);
        $serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$this->dateIsValid($date) || $date < date('Y-m-d') || !$dentistId || !$serviceId) $api->respond_error('Choose a valid future date, dentist, and service.', 422);

        $service = $database->raw('SELECT duration_min FROM services WHERE service_id=? AND is_active=1 LIMIT 1', [$serviceId])->fetch(PDO::FETCH_ASSOC);
        if (!$service) $api->respond_error('Service not found or inactive.', 404);
        $dentist = $database->raw("SELECT d.dentist_id FROM dentists d JOIN users u ON u.user_id=d.user_id WHERE d.dentist_id=? AND u.status='active' LIMIT 1", [$dentistId])->fetch(PDO::FETCH_ASSOC);
        if (!$dentist) $api->respond_error('Dentist not found or inactive.', 404);
        $duration = max(15, (int) $service['duration_min']);
        $day = (int) (new DateTimeImmutable($date))->format('w');
        $schedules = $database->raw('SELECT start_time, end_time FROM dentist_schedules WHERE dentist_id=? AND day_of_week=? ORDER BY start_time', [$dentistId, $day])->fetchAll(PDO::FETCH_ASSOC);
        $booked = $database->raw("SELECT a.appt_time, COALESCE(a.end_time, ADDTIME(a.appt_time, SEC_TO_TIME(GREATEST(s.duration_min,15) * 60))) AS end_time FROM appointments a JOIN services s ON s.service_id=a.service_id WHERE a.dentist_id=? AND a.appt_date=? AND a.status NOT IN ('cancelled','no_show')", [$dentistId, $date])->fetchAll(PDO::FETCH_ASSOC);
        $slots = [];
        $timeSlots = [];
        foreach ($schedules as $schedule) {
            $start = new DateTimeImmutable($date . ' ' . $schedule['start_time']);
            $close = new DateTimeImmutable($date . ' ' . $schedule['end_time']);
            while ($start->modify('+' . $duration . ' minutes') <= $close) {
                $end = $start->modify('+' . $duration . ' minutes');
                $busy = false;
                foreach ($booked as $appointment) {
                    $bookedStart = new DateTimeImmutable($date . ' ' . $appointment['appt_time']);
                    $bookedEnd = new DateTimeImmutable($date . ' ' . ($appointment['end_time'] ?: $appointment['appt_time']));
                    if ($start < $bookedEnd && $end > $bookedStart) { $busy = true; break; }
                }
                $time = $start->format('H:i');
                $status = $busy ? 'booked' : ($this->slotIsInFuture($date, $start->format('H:i:s')) ? 'available' : 'past');
                $timeSlots[] = ['time' => $time, 'status' => $status];
                if ($status === 'available') $slots[] = $time;
                $start = $start->modify('+15 minutes');
            }
        }
        $uniqueTimeSlots = [];
        foreach ($timeSlots as $timeSlot) $uniqueTimeSlots[$timeSlot['time']] = $timeSlot;
        $api->respond(['data' => ['date' => $date, 'duration_min' => $duration, 'slots' => array_values(array_unique($slots)), 'time_slots' => array_values($uniqueTimeSlots)]]);
    }

    public function calendar()
    {
        $api = $this->api();
        $database = $this->database();
        medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $dentistId = filter_var($_GET['dentist_id'] ?? null, FILTER_VALIDATE_INT);
        $serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT);
        $startText = substr((string) ($_GET['start'] ?? ''), 0, 10);
        $endText = substr((string) ($_GET['end'] ?? ''), 0, 10);
        if (!$dentistId || !$serviceId || !$this->dateIsValid($startText) || !$this->dateIsValid($endText) || $endText <= $startText) $api->respond_error('Choose a valid service, dentist, and calendar range.', 422);
        $startDate = new DateTimeImmutable($startText);
        $endDate = new DateTimeImmutable($endText);
        $today = new DateTimeImmutable('today');
        if ($endDate <= $today || $endDate->diff($startDate)->days > 45) $api->respond_error('Calendar range must be within the next 45 days.', 422);
        if ($startDate < $today) $startDate = $today;
        $service = $database->raw('SELECT service_name, duration_min FROM services WHERE service_id=? AND is_active=1 LIMIT 1', [$serviceId])->fetch(PDO::FETCH_ASSOC);
        if (!$service) $api->respond_error('Service not found or inactive.', 404);
        $dentist = $database->raw("SELECT dentist_id FROM dentists d JOIN users u ON u.user_id=d.user_id WHERE d.dentist_id=? AND u.status='active' LIMIT 1", [$dentistId])->fetch(PDO::FETCH_ASSOC);
        if (!$dentist) $api->respond_error('Dentist not found or inactive.', 404);
        $schedules = $database->raw('SELECT day_of_week, start_time, end_time FROM dentist_schedules WHERE dentist_id=? ORDER BY day_of_week, start_time', [$dentistId])->fetchAll(PDO::FETCH_ASSOC);
        $booked = $database->raw("SELECT a.appt_date, a.appt_time, COALESCE(a.end_time, ADDTIME(a.appt_time, SEC_TO_TIME(GREATEST(s.duration_min,15) * 60))) AS end_time FROM appointments a JOIN services s ON s.service_id=a.service_id WHERE a.dentist_id=? AND a.appt_date>=? AND a.appt_date<? AND a.status NOT IN ('cancelled','no_show')", [$dentistId, $startText, $endText])->fetchAll(PDO::FETCH_ASSOC);
        $events = [];
        $duration = max(15, (int) $service['duration_min']);
        for ($date = $startDate; $date < $endDate; $date = $date->modify('+1 day')) {
            $day = (int) $date->format('w');
            foreach ($booked as $appointment) {
                if ($appointment['appt_date'] !== $date->format('Y-m-d')) continue;
                $bookedStart = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $appointment['appt_time']);
                $bookedEnd = new DateTimeImmutable($date->format('Y-m-d') . ' ' . ($appointment['end_time'] ?: $appointment['appt_time']));
                $events[] = [
                    'id' => 'booked-' . $date->format('Y-m-d') . '-' . $appointment['appt_time'],
                    'title' => 'Already booked',
                    'start' => $bookedStart->format('Y-m-d\TH:i:s'),
                    'end' => $bookedEnd->format('Y-m-d\TH:i:s'),
                    'display' => 'block',
                    'backgroundColor' => '#f8e4e4',
                    'borderColor' => '#e4b7b7',
                    'textColor' => '#a24949',
                    'extendedProps' => ['appt_date' => $date->format('Y-m-d'), 'appt_time' => $appointment['appt_time'], 'slot_status' => 'booked'],
                ];
            }
            foreach ($schedules as $schedule) {
                if ((int) $schedule['day_of_week'] !== $day) continue;
                $slot = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $schedule['start_time']);
                $close = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $schedule['end_time']);
                while ($slot->modify('+' . $duration . ' minutes') <= $close) {
                    $slotEnd = $slot->modify('+' . $duration . ' minutes');
                    $busy = false;
                    foreach ($booked as $appointment) {
                        if ($appointment['appt_date'] !== $date->format('Y-m-d')) continue;
                        $bookedStart = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $appointment['appt_time']);
                        $bookedEnd = new DateTimeImmutable($date->format('Y-m-d') . ' ' . ($appointment['end_time'] ?: $appointment['appt_time']));
                        if ($slot < $bookedEnd && $slotEnd > $bookedStart) { $busy = true; break; }
                    }
                    if ($busy) {
                        $slot = $slot->modify('+15 minutes');
                        continue;
                    }
                    if ($this->slotIsInFuture($date->format('Y-m-d'), $slot->format('H:i:s'))) {
                        $events[] = ['id' => $slot->format('Y-m-d-H-i'), 'title' => $slot->format('H:i') . ' Available', 'start' => $slot->format('Y-m-d\TH:i:s'), 'end' => $slotEnd->format('Y-m-d\TH:i:s'), 'display' => 'block', 'backgroundColor' => '#dff3e8', 'borderColor' => '#acd9be', 'textColor' => '#26784e', 'extendedProps' => ['appt_date' => $date->format('Y-m-d'), 'appt_time' => $slot->format('H:i'), 'slot_status' => 'available']];
                    } else {
                        $events[] = ['id' => 'past-' . $slot->format('Y-m-d-H-i'), 'title' => 'Time has passed', 'start' => $slot->format('Y-m-d\TH:i:s'), 'end' => $slotEnd->format('Y-m-d\TH:i:s'), 'display' => 'block', 'backgroundColor' => '#eef0f2', 'borderColor' => '#d7dce0', 'textColor' => '#7d8790', 'extendedProps' => ['appt_date' => $date->format('Y-m-d'), 'appt_time' => $slot->format('H:i'), 'slot_status' => 'past']];
                    }
                    $slot = $slot->modify('+15 minutes');
                }
            }
        }
        $api->respond(['data' => $events]);
    }

    public function index()
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $api->respond(['data' => $this->appointmentRows($database, $user)]);
    }

    public function show($id)
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $statement = $database->raw(
            "SELECT a.appointment_id, a.patient_id, a.service_id, a.dentist_id, a.appt_date, a.appt_time,
                    a.end_time, a.status, a.remarks, a.cancel_reason, a.reschedule_reason, a.created_at,
                    s.service_name, s.price, du.username AS dentist,
                    CONCAT(pt.first_name, ' ', pt.last_name) AS patient, pt.email AS patient_email
             FROM appointments a
             JOIN services s ON s.service_id=a.service_id
             JOIN patients pt ON pt.patient_id=a.patient_id
             LEFT JOIN dentists d ON d.dentist_id=a.dentist_id
             LEFT JOIN users du ON du.user_id=d.user_id
             WHERE a.appointment_id=? LIMIT 1",
            [(int) $id]
        );
        $appointment = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) $api->respond_error('Appointment not found.', 404);
        if ($user['role_name'] === 'Patient') {
            $owner = $database->raw('SELECT patient_id FROM patients WHERE patient_id=? AND user_id=?', [(int) $appointment['patient_id'], (int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$owner) $api->respond_error('Appointment not found.', 404);
        }
        $api->respond(['data' => $appointment]);
    }

    public function history($id)
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $appointmentId = (int) $id;
        $appointment = $database->raw('SELECT patient_id FROM appointments WHERE appointment_id=? LIMIT 1', [$appointmentId])->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) $api->respond_error('Appointment not found.', 404);
        if ($user['role_name'] === 'Patient') {
            $owner = $database->raw('SELECT patient_id FROM patients WHERE patient_id=? AND user_id=?', [(int) $appointment['patient_id'], (int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$owner) $api->respond_error('Appointment not found.', 404);
        }
        $rows = $database->raw(
            "SELECT l.log_id, l.action, l.table_name, l.record_id, l.created_at, COALESCE(u.username, 'System') AS actor
             FROM audit_logs l LEFT JOIN users u ON u.user_id=l.user_id
             WHERE l.table_name='appointments' AND l.record_id=?
             ORDER BY l.created_at DESC, l.log_id DESC",
            [$appointmentId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $rows]);
    }

    public function store()
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $body = $api->body();
        $serviceId = filter_var($body['service_id'] ?? null, FILTER_VALIDATE_INT);
        $dentistId = filter_var($body['dentist_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string) ($body['appt_date'] ?? ''));
        $time = trim((string) ($body['appt_time'] ?? ''));
        if (!$serviceId || !$dentistId || !$this->dateIsValid($date) || $date < date('Y-m-d') || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) $api->respond_error('Choose an active service, dentist, and future appointment time.', 422);

        if ($user['role_name'] === 'Patient') {
            $patientFilter = 'p.user_id=?';
            $patientId = (int) $user['user_id'];
        } else {
            $patientId = filter_var($body['patient_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$patientId) $api->respond_error('Patient profile not found.', 404);
            $patientFilter = 'p.patient_id=?';
        }
        $booking = $database->raw(
            "SELECT p.patient_id, p.user_id AS patient_user_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    s.service_id, s.service_name, s.duration_min, active_dentist.user_id AS active_dentist_id
             FROM patients p
             LEFT JOIN services s ON s.service_id=? AND s.is_active=1
             LEFT JOIN dentists d ON d.dentist_id=?
             LEFT JOIN users active_dentist ON active_dentist.user_id=d.user_id AND active_dentist.status='active'
             WHERE $patientFilter AND p.is_archived=0
             LIMIT 1",
            [$serviceId, $dentistId, $patientId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$booking) $api->respond_error('Patient profile not found.', 404);
        if (!$booking['service_id']) $api->respond_error('Service not found or inactive.', 404);
        if (!$booking['active_dentist_id']) $api->respond_error('Dentist not found or inactive.', 404);
        $duration = max(15, (int) $booking['duration_min']);
        $time = strlen($time) === 5 ? $time . ':00' : $time;
        if (!$this->slotIsInFuture($date, $time)) $api->respond_error('Choose a time that has not already passed.', 422);

        $database->transaction();
        $database->raw('SELECT schedule_id FROM dentist_schedules WHERE dentist_id=? AND day_of_week=? FOR UPDATE', [$dentistId, (int) (new DateTimeImmutable($date))->format('w')])->fetchAll(PDO::FETCH_ASSOC);
        if (!$this->slotIsAvailable($database, $dentistId, $date, $time, $duration)) {
            $database->roll_back();
            $api->respond_error('That appointment slot is no longer available.', 409);
        }
        $end = (new DateTimeImmutable($date . ' ' . $time))->modify('+' . $duration . ' minutes')->format('H:i:s');
        $patientConflict = $database->raw(
            "SELECT a.appointment_id
             FROM appointments a JOIN services s ON s.service_id=a.service_id
             WHERE a.patient_id=? AND a.appt_date=?
               AND a.status NOT IN ('cancelled','no_show')
               AND a.appt_time<?
               AND COALESCE(a.end_time, ADDTIME(a.appt_time, SEC_TO_TIME(GREATEST(s.duration_min,15) * 60)))>?
             LIMIT 1 FOR UPDATE",
            [(int) $booking['patient_id'], $date, $end, $time]
        )->fetch(PDO::FETCH_ASSOC);
        if ($patientConflict) {
            $database->roll_back();
            $api->respond_error('You already have an appointment that overlaps this time. Choose another time.', 409);
        }
        $status = $user['role_name'] === 'Patient' ? 'pending' : 'confirmed';
        $database->raw('INSERT INTO appointments (patient_id, service_id, dentist_id, appt_date, appt_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, ?)', [(int) $booking['patient_id'], $serviceId, $dentistId, $date, $time, $end, $status]);
        $appointmentId = (int) $database->last_id();
        $eventKey = 'appointment:' . $appointmentId . ':created';
        $label = $booking['service_name'] . ' on ' . $date . ' at ' . substr($time, 0, 5);
        $this->notifyAppointmentPatient(
            $database,
            $appointmentId,
            $eventKey,
            $status === 'pending' ? 'Appointment request received' : 'Appointment booked',
            $status === 'pending'
                ? 'Your request for ' . $label . ' is waiting for clinic confirmation.'
                : 'Your appointment for ' . $label . ' has been booked by the clinic.',
            (int) $user['user_id']
        );
        $this->notifyAppointmentTeam(
            $eventKey,
            'New appointment booking',
            $booking['patient_name'] . ' booked ' . $label . '.',
            (int) $user['user_id'],
            (int) $user['user_id']
        );
        medicare_audit($database, (int) $user['user_id'], 'INSERT', 'appointments', $appointmentId);
        $database->commit();
        $this->notifyPatient($database, $appointmentId, 'appointment_created');
        $this->notifyAdminAppointment($database, $appointmentId, 'booked');
        $api->respond(['data' => ['appointment_id' => $appointmentId, 'status' => $status] ], 201);
    }

    public function cancel($id)
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Patient', 'Staff', 'Admin']);
        $body = $api->body();
        $reason = trim((string) ($body['cancel_reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) $api->respond_error('Provide a cancellation reason up to 255 characters.', 422);
        $database->transaction();
        $statement = $database->raw('SELECT a.patient_id, a.status, a.appt_date, a.appt_time, p.user_id FROM appointments a JOIN patients p ON p.patient_id=a.patient_id WHERE a.appointment_id=? LIMIT 1 FOR UPDATE', [(int) $id]);
        $appointment = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) { $database->roll_back(); $api->respond_error('Appointment not found.', 404); }
        if ($user['role_name'] === 'Patient' && (int) $appointment['user_id'] !== (int) $user['user_id']) { $database->roll_back(); $api->respond_error('Appointment not found.', 404); }
        if (!in_array($appointment['status'], ['pending', 'confirmed'], true) || !$this->slotIsInFuture($appointment['appt_date'], $appointment['appt_time'])) { $database->roll_back(); $api->respond_error('This appointment cannot be cancelled.', 409); }
        $database->raw('UPDATE appointments SET status=?, cancel_reason=? WHERE appointment_id=?', ['cancelled', $reason, (int) $id]);
        $eventKey = 'appointment:' . (int) $id . ':cancelled';
        $this->notifyAppointmentPatient($database, (int) $id, $eventKey, 'Appointment cancelled', 'Your appointment on ' . $appointment['appt_date'] . ' at ' . substr($appointment['appt_time'], 0, 5) . ' was cancelled.', (int) $user['user_id']);
        if ($user['role_name'] === 'Patient') {
            $this->notifyAppointmentTeam($eventKey, 'Appointment cancelled by patient', 'A patient cancelled appointment #' . (int) $id . '. Reason: ' . $reason, (int) $user['user_id']);
        }
        $database->commit();
        medicare_audit($database, (int) $user['user_id'], 'UPDATE', 'appointments', (int) $id);
        $this->notifyPatient($database, (int) $id, 'appointment_cancelled');
        if ($user['role_name'] === 'Patient') {
            $this->notifyAdminAppointment($database, (int) $id, 'cancelled');
        }
        $api->respond(['message' => 'Appointment cancelled.']);
    }

    public function setStatus($id)
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Staff', 'Admin']);
        $status = (string) (($api->body())['status'] ?? '');
        if (!in_array($status, ['confirmed', 'completed', 'no_show'], true)) $api->respond_error('Select a valid appointment status. Use the cancel action to provide a cancellation reason.', 422);
        $database->transaction();
        $exists = $database->raw(
            "SELECT a.status, a.appt_date, a.appt_time, p.user_id AS patient_user_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name, s.service_name,
                    COALESCE(a.end_time, ADDTIME(a.appt_time, SEC_TO_TIME(GREATEST(s.duration_min, 15) * 60))) AS appointment_end,
                    s.price,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.appointment_id=a.appointment_id AND p.status='verified'), 0) AS verified_amount
             FROM appointments a JOIN services s ON s.service_id=a.service_id
             JOIN patients p ON p.patient_id=a.patient_id
             WHERE a.appointment_id=? LIMIT 1 FOR UPDATE",
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$exists) { $database->roll_back(); $api->respond_error('Appointment not found.', 404); }
        $transitions = ['pending' => ['confirmed'], 'confirmed' => ['completed', 'no_show']];
        if (!in_array($status, $transitions[$exists['status']] ?? [], true)) { $database->roll_back(); $api->respond_error('This appointment status cannot be changed that way.', 409); }
        if ($status === 'completed') {
            $appointmentEnd = $exists['appt_date'] . ' ' . $exists['appointment_end'];
            if (strtotime($appointmentEnd) === false || strtotime($appointmentEnd) > time()) {
                $database->roll_back();
                $api->respond_error('This appointment can only be completed after its scheduled date and end time.', 409);
            }
            if ((float) $exists['verified_amount'] < (float) $exists['price']) {
                $database->roll_back();
                $api->respond_error('This appointment cannot be completed until the full payment is verified.', 409);
            }
        }
        $database->raw('UPDATE appointments SET status=? WHERE appointment_id=?', [$status, (int) $id]);
        $this->notifyAppointmentPatient(
            $database,
            (int) $id,
            'appointment:' . (int) $id . ':' . $status,
            'Appointment ' . str_replace('_', ' ', $status),
            'Your appointment for ' . $exists['service_name'] . ' is now ' . str_replace('_', ' ', $status) . '.',
            (int) $user['user_id']
        );
        $database->commit();
        medicare_audit($database, (int) $user['user_id'], 'UPDATE', 'appointments', (int) $id);
        $this->notifyPatient($database, (int) $id, 'appointment_status');
        $api->respond(['data' => ['appointment_id' => (int) $id, 'status' => $status]]);
    }

    public function reschedule($id)
    {
        $api = $this->api();
        $database = $this->database();
        $user = medicare_require_role($database, $api, ['Staff', 'Admin']);
        $body = $api->body();
        $date = trim((string) ($body['appt_date'] ?? ''));
        $time = trim((string) ($body['appt_time'] ?? ''));
        $reason = trim((string) ($body['reschedule_reason'] ?? ''));
        $id = (int) $id;
        if (!$this->dateIsValid($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) || $reason === '' || mb_strlen($reason) > 255) $api->respond_error('Choose a valid future date, time, and reschedule reason.', 422);
        $time = strlen($time) === 5 ? $time . ':00' : $time;
        if (!$this->slotIsInFuture($date, $time)) $api->respond_error('Choose a time that has not already passed.', 422);

        $database->transaction();
        $appointment = $database->raw('SELECT a.dentist_id, a.service_id, a.status, p.user_id AS patient_user_id, s.service_name FROM appointments a JOIN patients p ON p.patient_id=a.patient_id JOIN services s ON s.service_id=a.service_id WHERE a.appointment_id=? LIMIT 1 FOR UPDATE', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) { $database->roll_back(); $api->respond_error('Appointment not found.', 404); }
        if (!$appointment['dentist_id'] || !in_array($appointment['status'], ['pending','confirmed'], true)) { $database->roll_back(); $api->respond_error('This appointment cannot be rescheduled.', 409); }
        $service = $database->raw('SELECT duration_min FROM services WHERE service_id=?', [(int) $appointment['service_id']])->fetch(PDO::FETCH_ASSOC);
        $duration = max(15, (int) ($service['duration_min'] ?? 30));
        $database->raw('SELECT schedule_id FROM dentist_schedules WHERE dentist_id=? AND day_of_week=? FOR UPDATE', [(int) $appointment['dentist_id'], (int) (new DateTimeImmutable($date))->format('w')])->fetchAll(PDO::FETCH_ASSOC);
        if (!$this->slotIsAvailable($database, (int) $appointment['dentist_id'], $date, $time, $duration, $id)) {
            $database->roll_back();
            $api->respond_error('That appointment slot is no longer available.', 409);
        }
        $end = (new DateTimeImmutable($date . ' ' . $time))->modify('+' . $duration . ' minutes')->format('H:i:s');
        $database->raw('UPDATE appointments SET appt_date=?, appt_time=?, end_time=?, reschedule_reason=? WHERE appointment_id=?', [$date, $time, $end, $reason, $id]);
        $eventKey = 'appointment:' . $id . ':rescheduled:' . $date . 'T' . substr($time, 0, 5);
        $this->notifyAppointmentPatient($database, $id, $eventKey, 'Appointment rescheduled', 'Your ' . $appointment['service_name'] . ' appointment has been rescheduled to ' . $date . ' at ' . substr($time, 0, 5) . '.', (int) $user['user_id']);
        $database->commit();
        medicare_audit($database, (int) $user['user_id'], 'UPDATE', 'appointments', $id);
        $this->notifyPatient($database, $id, 'appointment_rescheduled');
        $api->respond(['data' => ['appointment_id' => $id, 'appt_date' => $date, 'appt_time' => $time]]);
    }
}
