<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');
$router->post('/api/auth/login', 'Auth::login');
$router->post('/api/auth/logout', 'Auth::logout');
$router->get('/api/auth/account', 'Auth::account');
$router->post('/api/auth/register', 'Auth::register');
$router->post('/api/auth/verify-email', 'Auth::verifyEmail');
$router->post('/api/auth/resend-verification', 'Auth::resendVerificationEmail');
$router->post('/api/auth/password-reset/request', 'Auth::requestPasswordReset');
$router->post('/api/auth/password-reset/validate', 'Auth::validatePasswordReset');
$router->post('/api/auth/password-reset/confirm', 'Auth::confirmPasswordReset');
$router->get('/api/health', 'Api_status::health');
$router->get('/api/public/overview', 'Public_catalog::overview');
$router->get('/api/reports', 'Reports::index');
$router->get('/api/prescriptions', 'Workspace::prescriptions');
$router->post('/api/prescriptions', 'Workspace::prescriptions');
$router->get('/api/messages', 'Workspace::messages');
$router->post('/api/messages', 'Workspace::messages');
$router->get('/api/messages/unread-count', 'Workspace::unreadMessages');
$router->get('/api/workspace/unread-counts', 'Workspace::unreadCounts');
$router->post('/api/messages/read', 'Workspace::markMessagesRead');
$router->get('/api/settings', 'Workspace::settings');
$router->put('/api/settings', 'Workspace::settings');
$router->get('/api/patients/{id}/tooth-chart', 'Workspace::toothChart')->where_number('id');
$router->put('/api/patients/{id}/tooth-chart', 'Workspace::toothChart')->where_number('id');
$router->get('/api/patient/dashboard', 'Patient_portal::dashboard');
$router->get('/api/patient/profile', 'Patient_portal::profile');
$router->put('/api/patient/profile', 'Patient_portal::updateProfile');
$router->get('/api/patient/notifications', 'Patient_portal::notifications');
$router->get('/api/notifications', 'Notifications::index');
$router->get('/api/notifications/unread-count', 'Notifications::unread');
$router->post('/api/notifications/{id}/read', 'Notifications::read')->where_number('id');
$router->post('/api/notifications/read-all', 'Notifications::readAll');
$router->get('/api/payments/mine', 'Clinic_operations::myPayments');
$router->get('/api/payments/charges', 'Clinic_operations::patientCharges');
$router->get('/api/payments/overview', 'Clinic_operations::patientPaymentOverview');
$router->get('/api/payment-methods/active', 'Clinic_operations::activePaymentMethods');
$router->post('/api/payments/paymongo/checkout', 'Clinic_operations::createPaymongoCheckout');
$router->post('/api/payments/paymongo/confirm', 'Clinic_operations::confirmPaymongoPayment');
$router->post('/api/payments', 'Clinic_operations::submitPayment');
$router->get('/api/payments/receipt/{id}', 'Clinic_operations::receipt')->where_number('id');
$router->get('/api/payments/pending', 'Clinic_operations::pendingPayments');
$router->get('/api/payments/history', 'Clinic_operations::paymentHistory');
$router->post('/api/payments/{id}/status', 'Clinic_operations::paymentStatus')->where_number('id');
$router->get('/api/treatment-records', 'Clinic_operations::treatments');
$router->post('/api/treatment-records', 'Clinic_operations::addTreatment');
$router->get('/api/email-notifications', 'Clinic_operations::notificationLog');
$router->post('/api/email-notifications', 'Clinic_operations::saveNotificationResult');
$router->get('/api/services', 'Clinic_operations::services');
$router->post('/api/services', 'Clinic_operations::saveService');
$router->put('/api/services/{id}', 'Clinic_operations::saveService')->where_number('id');
$router->delete('/api/services/{id}', 'Clinic_operations::archiveService')->where_number('id');
$router->get('/api/payment-methods', 'Clinic_operations::paymentMethods');
$router->post('/api/payment-methods', 'Clinic_operations::savePaymentMethod');
$router->put('/api/payment-methods/{id}', 'Clinic_operations::savePaymentMethod')->where_number('id');
$router->get('/api/dentists', 'Clinic_operations::dentists');
$router->post('/api/dentist-schedules', 'Clinic_operations::saveSchedule');
$router->delete('/api/dentist-schedules/{id}', 'Clinic_operations::deleteSchedule')->where_number('id');
$router->get('/api/users', 'Clinic_operations::users');
$router->post('/api/users/{id}/status', 'Clinic_operations::userStatus')->where_number('id');
$router->get('/api/audit-logs', 'Clinic_operations::auditLogs');
$router->get('/api/staff/dashboard', 'Clinic_operations::staffDashboard');
$router->get('/api/admin/dashboard', 'Clinic_operations::adminDashboard');
$router->get('/api/appointments/calendar', 'Appointments::calendar');
$router->get('/api/appointments/availability', 'Appointments::availability');
$router->post('/api/appointments/{id}/email', 'Clinic_operations::sendAppointmentNotification')->where_number('id');
$router->post('/api/internal/appointment-reminders', 'Clinic_operations::sendDueAppointmentReminders');
$router->get('/api/appointments', 'Appointments::index');
$router->post('/api/appointments', 'Appointments::store');
$router->get('/api/appointments/{id}', 'Appointments::show')->where_number('id');
$router->post('/api/appointments/{id}/cancel', 'Appointments::cancel')->where_number('id');
$router->post('/api/appointments/{id}/status', 'Appointments::setStatus')->where_number('id');
$router->post('/api/appointments/{id}/reschedule', 'Appointments::reschedule')->where_number('id');
$router->get('/api/appointments/{id}/history', 'Appointments::history')->where_number('id');
$router->get('/api/patients', 'Patients::index');
$router->get('/api/patients/{id}/appointments', 'Patients::appointments')->where_number('id');
$router->get('/api/patients/{id}', 'Patients::show')->where_number('id');
$router->post('/api/patients', 'Patients::store');
$router->put('/api/patients/{id}', 'Patients::update')->where_number('id');
$router->delete('/api/patients/{id}', 'Patients::delete')->where_number('id');
$router->get('/__migrate', 'Maintenance::migrate');
