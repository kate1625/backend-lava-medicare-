<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Public_catalog extends Controller
{
    public function overview()
    {
        $api = $this->call->library('api');
        $database = $this->call->database();

        $columnsStatement = $database->raw(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = ? AND column_name IN (?, ?, ?, ?, ?, ?, ?)) OR (table_name = ? AND column_name IN (?, ?, ?, ?, ?, ?, ?)) OR (table_name = ? AND column_name IN (?, ?, ?)) OR (table_name = ? AND column_name IN (?, ?, ?)))',
            [
                'services', 'service_id', 'service_name', 'category', 'description', 'price', 'duration_min', 'is_active',
                'dentists', 'dentist_id', 'specialization', 'user_id', 'id', 'full_name', 'specialty', 'active',
                'users', 'user_id', 'username', 'status',
                'treatments', 'id', 'name', 'price',
            ]
        );
        $available = [];
        foreach ($columnsStatement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $available[$column['table_name'] . '.' . $column['column_name']] = true;
        }
        $required = [
            'services.service_id', 'services.service_name', 'services.category', 'services.description', 'services.price', 'services.duration_min', 'services.is_active',
            'dentists.dentist_id', 'dentists.specialization', 'dentists.user_id', 'users.user_id', 'users.username', 'users.status',
        ];
        if (!array_diff($required, array_keys($available))) {
            $servicesStatement = $database->raw(
                'SELECT service_id, service_name, category, description, price, duration_min FROM services WHERE is_active = ? ORDER BY service_name',
                [1]
            );
            $dentistsStatement = $database->raw(
                'SELECT d.dentist_id, d.specialization, u.username AS display_name FROM dentists d JOIN users u ON u.user_id = d.user_id WHERE u.status = ? ORDER BY u.username',
                ['active']
            );
        } elseif (
            !array_diff(
                [
                    'treatments.id', 'treatments.name', 'treatments.price',
                    'dentists.id', 'dentists.full_name', 'dentists.specialty', 'dentists.active',
                ],
                array_keys($available)
            )
        ) {
            $servicesStatement = $database->raw(
                'SELECT id AS service_id, name AS service_name, NULL AS category, NULL AS description, price, 30 AS duration_min FROM treatments ORDER BY name'
            );
            $dentistsStatement = $database->raw(
                'SELECT id AS dentist_id, specialty AS specialization, full_name AS display_name FROM dentists WHERE active = ? ORDER BY full_name',
                [1]
            );
        } else {
            $api->respond_error('Clinic database is missing the MediCare+ catalog schema.', 503);
        }

        header('Cache-Control: public, max-age=300, must-revalidate');
        $api->respond(['data' => [
            'services' => $servicesStatement->fetchAll(PDO::FETCH_ASSOC),
            'dentists' => $dentistsStatement->fetchAll(PDO::FETCH_ASSOC),
        ]]);
    }
}
