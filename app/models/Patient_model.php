<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Patient_model extends Model
{
    protected $table = 'patients';

    private const SELECT_FIELDS = "patient_id AS id, first_name, last_name, email, contact_no AS phone, birth_date AS date_of_birth, COALESCE(sex, 'other') AS gender, address, IF(is_archived=1, 'inactive', 'active') AS status, created_at, NULL AS updated_at";

    public function listPatients()
    {
        $statement = $this->db->raw(
            'SELECT ' . self::SELECT_FIELDS . ' FROM patients WHERE is_archived=0 ORDER BY created_at DESC, patient_id DESC'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPatient(int $id)
    {
        $statement = $this->db->raw(
            'SELECT ' . self::SELECT_FIELDS . ' FROM patients WHERE patient_id=? AND is_archived=0 LIMIT 1',
            [$id]
        );
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function createPatient(array $patient)
    {
        $this->db->raw(
            'INSERT INTO patients (user_id, first_name, last_name, birth_date, sex, contact_no, email, address, is_archived) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient['first_name'], $patient['last_name'], $patient['date_of_birth'] ?: null, $patient['gender'], $patient['phone'] ?: null, $patient['email'] ?: null, $patient['address'] ?: null, $patient['status'] === 'inactive' ? 1 : 0]
        );
        return $this->findPatient((int) $this->db->last_id());
    }

    public function updatePatient(int $id, array $patient)
    {
        $this->db->raw(
            'UPDATE patients SET first_name=?, last_name=?, birth_date=?, sex=?, contact_no=?, email=?, address=?, is_archived=? WHERE patient_id=?',
            [$patient['first_name'], $patient['last_name'], $patient['date_of_birth'] ?: null, $patient['gender'], $patient['phone'] ?: null, $patient['email'] ?: null, $patient['address'] ?: null, $patient['status'] === 'inactive' ? 1 : 0, $id]
        );
        return $this->findPatient($id);
    }

    public function archivePatient(int $id)
    {
        return $this->db->raw('UPDATE patients SET is_archived=1 WHERE patient_id=?', [$id]);
    }
}
