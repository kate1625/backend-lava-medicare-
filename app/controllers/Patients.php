<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Patients extends Controller
{
    private function authorize($api)
    {
        $database = $this->call->database();
        $user = medicare_require_role($database, $api, ['Staff', 'Admin']);
        return [$database, $user];
    }

    private function validatePatient(array $input): array
    {
        $fields = [];
        $errors = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'gender', 'address', 'status'] as $key) {
            $value = $input[$key] ?? ($key === 'status' ? 'active' : '');
            if (!is_string($value)) {
                $errors[$key] = 'Enter a valid value.';
                $value = '';
            }
            $fields[$key] = trim($value);
        }

        foreach (['first_name' => 50, 'last_name' => 50] as $key => $max) {
            if ($fields[$key] === '' || mb_strlen($fields[$key]) > $max) $errors[$key] = "Enter 1 to {$max} characters.";
        }
        if ($fields['email'] !== '' && (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($fields['email']) > 100)) $errors['email'] = 'Enter a valid email address up to 100 characters.';
        if (mb_strlen($fields['phone']) > 20) $errors['phone'] = 'Phone number must be 20 characters or fewer.';
        if (mb_strlen($fields['address']) > 255) $errors['address'] = 'Address must be 255 characters or fewer.';
        if (!in_array($fields['gender'], ['male', 'female', 'other'], true)) $errors['gender'] = 'Select male, female, or other.';
        if (!in_array($fields['status'], ['active', 'inactive'], true)) $errors['status'] = 'Select active or inactive.';
        if ($fields['date_of_birth'] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fields['date_of_birth']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $fields['date_of_birth'] || $date > new DateTimeImmutable('today')) {
                $errors['date_of_birth'] = 'Enter a valid date of birth that is not in the future.';
            }
        }
        return [$fields, $errors];
    }

    private function model()
    {
        return $this->call->model('Patient_model');
    }

    public function index()
    {
        $api = $this->call->library('api');
        $this->authorize($api);
        $api->respond(['data' => $this->model()->listPatients()]);
    }

    public function show($id)
    {
        $api = $this->call->library('api');
        $this->authorize($api);
        $patient = $this->model()->findPatient((int) $id);
        if (!$patient) $api->respond_error('Patient record not found.', 404);
        $api->respond(['data' => $patient]);
    }

    public function appointments($id)
    {
        $api = $this->call->library('api');
        [$database] = $this->authorize($api);
        $patientId = (int) $id;
        if (!$this->model()->findPatient($patientId)) $api->respond_error('Patient record not found.', 404);
        $rows = $database->raw(
            "SELECT a.appointment_id, a.appt_date, a.appt_time, a.end_time, a.status, a.remarks,
                    a.cancel_reason, a.reschedule_reason, a.created_at, s.service_name, s.price,
                    COALESCE(du.username, 'Unassigned') AS dentist,
                    (SELECT p.status FROM payments p WHERE p.appointment_id=a.appointment_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_status
             FROM appointments a
             JOIN services s ON s.service_id=a.service_id
             LEFT JOIN dentists d ON d.dentist_id=a.dentist_id
             LEFT JOIN users du ON du.user_id=d.user_id
             WHERE a.patient_id=?
             ORDER BY a.appt_date DESC, a.appt_time DESC
             LIMIT 200",
            [$patientId]
        )->fetchAll(PDO::FETCH_ASSOC);
        $api->respond(['data' => $rows]);
    }

    public function store()
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->authorize($api);
        $input = $api->body();
        [$patient, $errors] = $this->validatePatient(is_array($input) ? $input : []);
        if ($errors) $api->respond(['error' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);
        $created = $this->model()->createPatient($patient);
        medicare_audit($database, (int) $user['user_id'], 'INSERT', 'patients', (int) $created['id']);
        $api->respond(['data' => $created], 201);
    }

    public function update($id)
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->authorize($api);
        $id = (int) $id;
        if (!$this->model()->findPatient($id)) $api->respond_error('Patient record not found.', 404);
        [$patient, $errors] = $this->validatePatient($api->body());
        if ($errors) $api->respond(['error' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);
        $updated = $this->model()->updatePatient($id, $patient);
        medicare_audit($database, (int) $user['user_id'], 'UPDATE', 'patients', $id);
        $api->respond(['data' => $updated]);
    }

    public function delete($id)
    {
        $api = $this->call->library('api');
        [$database, $user] = $this->authorize($api);
        $id = (int) $id;
        if (!$this->model()->findPatient($id)) $api->respond_error('Patient record not found.', 404);
        $this->model()->archivePatient($id);
        medicare_audit($database, (int) $user['user_id'], 'ARCHIVE', 'patients', $id);
        $api->respond(['message' => 'Patient record archived.']);
    }
}
