<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Patient_portal extends Controller
{
    private function context($api): array
    {
        $database = $this->call->database();
        $user = medicare_require_role($database, $api, ['Patient']);
        $patient = $database->raw('SELECT * FROM patients WHERE user_id=? AND is_archived=0 LIMIT 1', [(int) $user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$patient) $api->respond_error('No patient profile is linked to this account. Contact clinic staff.', 404);
        return [$database, $user, $patient];
    }

    public function dashboard()
    {
        $api = $this->call->library('api');
        [$database, $user, $patient] = $this->context($api);
        $appointments = $database->raw(
            "SELECT a.appointment_id, a.appt_date, a.appt_time, a.status, s.service_name, u.username AS dentist FROM appointments a JOIN services s ON s.service_id=a.service_id LEFT JOIN dentists d ON d.dentist_id=a.dentist_id LEFT JOIN users u ON u.user_id=d.user_id WHERE a.patient_id=? ORDER BY a.appt_date DESC, a.appt_time DESC LIMIT 10",
            [(int) $patient['patient_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $upcoming = $database->raw(
            "SELECT a.appointment_id, a.appt_date, a.appt_time, a.status, s.service_name, s.price, u.username AS dentist FROM appointments a JOIN services s ON s.service_id=a.service_id LEFT JOIN dentists d ON d.dentist_id=a.dentist_id LEFT JOIN users u ON u.user_id=d.user_id WHERE a.patient_id=? AND TIMESTAMP(a.appt_date,a.appt_time)>=NOW() AND a.status IN ('pending','confirmed') ORDER BY a.appt_date, a.appt_time LIMIT 5",
            [(int) $patient['patient_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $history = $database->raw("SELECT DATE_FORMAT(appt_date, '%Y-%m') AS month, COUNT(*) AS total FROM appointments WHERE patient_id=? AND status='completed' GROUP BY month ORDER BY month", [(int) $patient['patient_id']])->fetchAll(PDO::FETCH_ASSOC);
        $activity = $database->raw(
            "SELECT activity_type, activity_date, description, status, appointment_id FROM (
                SELECT 'appointment' AS activity_type, a.created_at AS activity_date, CONCAT('Appointment: ',s.service_name) AS description, a.status, a.appointment_id FROM appointments a JOIN services s ON s.service_id=a.service_id WHERE a.patient_id=?
                UNION ALL
                SELECT 'treatment' AS activity_type, tr.created_at AS activity_date, COALESCE(tr.treatment_done,tr.diagnosis,'Dental record updated') AS description, NULL AS status, tr.appointment_id FROM treatment_records tr WHERE tr.patient_id=?
                UNION ALL
                SELECT 'payment' AS activity_type, pay.paid_at AS activity_date, CONCAT('Payment for appointment #',pay.appointment_id) AS description, pay.status, pay.appointment_id FROM payments pay JOIN appointments a ON a.appointment_id=pay.appointment_id WHERE a.patient_id=?
            ) activity_rows ORDER BY activity_date DESC LIMIT 8",
            [(int) $patient['patient_id'], (int) $patient['patient_id'], (int) $patient['patient_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $balance = $database->raw(
            "SELECT COALESCE(SUM(GREATEST(s.price-COALESCE(v.verified_amount,0),0)),0) FROM appointments a JOIN services s ON s.service_id=a.service_id LEFT JOIN (SELECT appointment_id,SUM(amount) AS verified_amount FROM payments WHERE status='verified' GROUP BY appointment_id) v ON v.appointment_id=a.appointment_id WHERE a.patient_id=? AND a.status NOT IN ('cancelled','no_show')",
            [(int) $patient['patient_id']]
        )->fetchColumn();
        $summary = $database->raw(
            "SELECT COUNT(*) AS total, SUM(status='pending') AS pending, SUM(status='confirmed') AS confirmed, SUM(status='completed') AS completed FROM appointments WHERE patient_id=?",
            [(int) $patient['patient_id']]
        )->fetch(PDO::FETCH_ASSOC);
        $api->respond(['data' => ['patient' => ['first_name' => $patient['first_name'], 'last_name' => $patient['last_name']], 'appointments' => $appointments, 'upcoming_appointments' => $upcoming, 'appointment_history' => $history, 'recent_activity' => $activity, 'outstanding_balance' => (float) $balance, 'summary' => $summary]]);
    }

    public function profile()
    {
        $api = $this->call->library('api');
        [$database, $user, $patient] = $this->context($api);
        $history = $database->raw(
            'SELECT tr.record_id, tr.tooth_no, tr.diagnosis, tr.treatment_done, tr.notes, tr.created_at, u.username AS dentist FROM treatment_records tr LEFT JOIN dentists d ON d.dentist_id=tr.dentist_id LEFT JOIN users u ON u.user_id=d.user_id WHERE tr.patient_id=? ORDER BY tr.created_at DESC LIMIT 100',
            [(int) $patient['patient_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $toothChart = $database->raw(
            'SELECT tooth_no, condition_name, updated_at FROM tooth_conditions WHERE patient_id=? ORDER BY tooth_no',
            [(int) $patient['patient_id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => ['profile' => $patient, 'treatment_history' => $history, 'tooth_chart' => $toothChart]]);
    }

    public function updateProfile()
    {
        $api = $this->call->library('api');
        [$database, $user, $patient] = $this->context($api);
        $body = $api->body();
        $fields = [];
        foreach (['contact_no' => 20, 'email' => 100, 'address' => 255, 'emergency_contact_name' => 100, 'emergency_contact_no' => 20, 'allergies' => 5000] as $field => $max) {
            $value = trim((string) ($body[$field] ?? ''));
            if (mb_strlen($value) > $max) $api->respond_error("{$field} is too long.", 422);
            if ($field === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $api->respond_error('Enter a valid email address.', 422);
            $fields[$field] = $value === '' ? null : $value;
        }
        $database->raw('UPDATE patients SET contact_no=?, email=?, address=?, emergency_contact_name=?, emergency_contact_no=?, allergies=? WHERE patient_id=?', [...array_values($fields), (int) $patient['patient_id']]);
        medicare_audit($database, (int) $user['user_id'], 'UPDATE', 'patients', (int) $patient['patient_id']);
        $api->respond(['message' => 'Profile updated successfully.']);
    }

    public function notifications()
    {
        $api = $this->call->library('api');
        [$database, $user, $patient] = $this->context($api);
        $items = $database->raw("SELECT notification_id, type, subject, status, scheduled_at, sent_at, error_message FROM email_notifications WHERE patient_id=? AND LEFT(type, 6)<>'admin_' ORDER BY COALESCE(sent_at, scheduled_at) DESC, notification_id DESC", [(int) $patient['patient_id']])->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $items]);
    }
}
