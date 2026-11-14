<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'helpers/medicare_auth_helper.php';

class Workspace extends Controller
{
    private function ctx($roles) { $api=$this->call->library('api'); $db=$this->call->database(); $user=medicare_require_role($db,$api,$roles); return [$api,$db,$user]; }


    public function prescriptions()
    {
        [$api,$db,$user]=$this->ctx(['Patient','Staff','Admin']);
        if ($_SERVER['REQUEST_METHOD']==='GET') {
            if($user['role_name']==='Patient'){
                $patient=$db->raw('SELECT patient_id FROM patients WHERE user_id=? AND is_archived=0 LIMIT 1',[(int)$user['user_id']])->fetch(PDO::FETCH_ASSOC);
                if(!$patient)$api->respond_error('No patient profile is linked to this account.',404);
                $rows=$db->raw("SELECT r.prescription_id,r.patient_id,r.appointment_id,a.appt_date,s.service_name,CONCAT(p.first_name,' ',p.last_name) patient_name,u.username prescribed_by,r.medication,r.directions,r.status,r.created_at FROM prescriptions r JOIN patients p ON p.patient_id=r.patient_id JOIN users u ON u.user_id=r.created_by LEFT JOIN appointments a ON a.appointment_id=r.appointment_id LEFT JOIN services s ON s.service_id=a.service_id WHERE r.patient_id=? ORDER BY r.created_at DESC",[(int)$patient['patient_id']])->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows=$db->raw("SELECT r.prescription_id,r.patient_id,r.appointment_id,a.appt_date,s.service_name,CONCAT(p.first_name,' ',p.last_name) patient_name,u.username prescribed_by,r.medication,r.directions,r.status,r.created_at FROM prescriptions r JOIN patients p ON p.patient_id=r.patient_id JOIN users u ON u.user_id=r.created_by LEFT JOIN appointments a ON a.appointment_id=r.appointment_id LEFT JOIN services s ON s.service_id=a.service_id ORDER BY r.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            }
            $api->respond(['data'=>$rows]);
        }
        if($user['role_name']==='Patient')$api->respond_error('Only clinic staff can create prescription records.',403);
        $api->require_method('POST'); $b=$api->body(); $pid=filter_var($b['patient_id']??null,FILTER_VALIDATE_INT); $appointmentValue=$b['appointment_id']??null; $appointmentId=null; $med=trim((string)($b['medication']??'')); $directions=trim((string)($b['directions']??''));
        if($appointmentValue!==null&&$appointmentValue!==''){$validatedAppointmentId=filter_var($appointmentValue,FILTER_VALIDATE_INT);if(!$validatedAppointmentId||$validatedAppointmentId<1)$api->respond_error('Choose a valid appointment or leave the appointment field blank.',422);$appointmentId=(int)$validatedAppointmentId;}
        if(!$pid||$med===''||mb_strlen($med)>190||$directions===''||mb_strlen($directions)>10000)$api->respond_error('Choose an active patient and enter medication and directions within the allowed length.',422);
        $patientExists=$db->raw('SELECT patient_id, user_id, email, first_name, last_name FROM patients WHERE patient_id=? AND is_archived=0',[$pid])->fetch(PDO::FETCH_ASSOC);
        if(!$patientExists)$api->respond_error('Active patient record not found.',404);
        if($appointmentId!==null){$appointment=$db->raw('SELECT appointment_id FROM appointments WHERE appointment_id=? AND patient_id=?',[$appointmentId,$pid])->fetch(PDO::FETCH_ASSOC);if(!$appointment)$api->respond_error('The selected appointment does not belong to this patient.',422);}
        $db->raw('INSERT INTO prescriptions(patient_id,appointment_id,created_by,medication,directions) VALUES(?,?,?,?,?)',[$pid,$appointmentId,(int)$user['user_id'],$med,$directions]); $id=(int)$db->raw('SELECT LAST_INSERT_ID()')->fetchColumn(); medicare_audit($db,(int)$user['user_id'],'INSERT','prescriptions',$id);
        $patientAccount=$db->raw('SELECT user_id FROM patients WHERE patient_id=? AND user_id IS NOT NULL',[$pid])->fetch(PDO::FETCH_ASSOC);
        $notificationSent=false;
        if($patientAccount){
            $this->call->model('Notification_model')->createOnce((int)$patientAccount['user_id'],(int)$user['user_id'],'New prescription added','A new prescription is ready to view.','prescription','patient','/patient/prescriptions','prescription:'.$id.':created');
            $notificationSent=true;
        }
        $emailSent=false;
        if(filter_var($patientExists['email']??'',FILTER_VALIDATE_EMAIL)){
            $prescriptionEmail=['patient_email'=>$patientExists['email'],'patient_name'=>$patientExists['first_name'].' '.$patientExists['last_name'],'medication'=>$med,'directions'=>$directions];
            $emailResult=$this->call->library('brevo_mailer')->sendPrescription($prescriptionEmail);
            $emailSent=$emailResult['sent'];
            if($appointmentId!==null){
                medicare_log_patient_email($db,$appointmentId,$pid,'prescription_'.$id,'New prescription instructions - MediCare+',$emailResult);
            }elseif(!$emailSent){
                error_log('MediCare+ prescription email failed for prescription #'.$id.': '.($emailResult['error']??'Unknown email error.'));
            }
        }else{
            error_log('MediCare+ prescription email skipped: patient #'.$pid.' has no valid email address.');
        }
        $api->respond(['data'=>['prescription_id'=>$id,'notification_sent'=>$notificationSent,'email_sent'=>$emailSent]],201);
    }

    public function messages()
    {
        [$api,$db,$user]=$this->ctx(['Staff','Admin','Patient']); $uid=(int)$user['user_id'];
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $rows=$db->raw('SELECT m.message_id,m.sender_id,m.recipient_id,m.body,m.is_read,m.created_at,s.username sender,r.username recipient FROM clinic_messages m JOIN users s ON s.user_id=m.sender_id JOIN users r ON r.user_id=m.recipient_id WHERE m.sender_id=? OR m.recipient_id=? ORDER BY m.created_at DESC LIMIT 300',[$uid,$uid])->fetchAll(PDO::FETCH_ASSOC);
            $contactSql="SELECT user_id,username,role_name,CASE WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL 75 SECOND) THEN 1 ELSE 0 END AS is_online FROM users JOIN roles USING(role_id) WHERE user_id<>? AND status='active'";
            if($user['role_name']==='Patient')$contactSql.=" AND role_name IN ('Staff','Admin')";
            $contacts=$db->raw($contactSql.' ORDER BY username',[$uid])->fetchAll(PDO::FETCH_ASSOC); $api->respond(['data'=>['messages'=>$rows,'contacts'=>$contacts]]);
        }
        $api->require_method('POST');$b=$api->body();$to=filter_var($b['recipient_id']??null,FILTER_VALIDATE_INT);$body=trim((string)($b['body']??''));
        if(!$to||$body===''||mb_strlen($body)>5000)$api->respond_error('Choose a recipient and enter a message (up to 5000 characters).',422);
        $exists=$db->raw("SELECT u.user_id,r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND u.status='active'",[$to])->fetch(PDO::FETCH_ASSOC);if(!$exists)$api->respond_error('Recipient not found.',404);
        if($user['role_name']==='Patient'&&!in_array($exists['role_name'],['Staff','Admin'],true))$api->respond_error('Patients may only contact clinic staff.',403);
        $db->raw('INSERT INTO clinic_messages(sender_id,recipient_id,body) VALUES(?,?,?)',[$uid,$to,$body]);$id=(int)$db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();medicare_audit($db,$uid,'INSERT','clinic_messages',$id);$api->respond(['data'=>['message_id'=>$id]],201);
    }

    public function unreadMessages()
    {
        [$api,$db,$user]=$this->ctx(['Staff','Admin','Patient']);
        $count=(int)$db->raw(
            'SELECT COUNT(*) FROM clinic_messages WHERE recipient_id=? AND is_read=0',
            [(int)$user['user_id']]
        )->fetchColumn();
        $api->respond(['data'=>['count'=>$count]]);
    }

    public function unreadCounts()
    {
        [$api,$db,$user]=$this->ctx(['Staff','Admin','Patient']);
        $userId=(int)$user['user_id'];
        $db->raw('UPDATE users SET last_seen_at=NOW() WHERE user_id=?',[$userId]);
        $counts=$db->raw(
            'SELECT (SELECT COUNT(*) FROM clinic_messages WHERE recipient_id=? AND is_read=0) AS messages, '
            . '(SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0) AS notifications',
            [$userId,$userId]
        )->fetch(PDO::FETCH_ASSOC);
        $api->respond(['data'=>[
            'messages'=>(int)($counts['messages']??0),
            'notifications'=>(int)($counts['notifications']??0),
        ]]);
    }

    public function markMessagesRead()
    {
        [$api,$db,$user]=$this->ctx(['Staff','Admin','Patient']);
        $api->require_method('POST');
        $db->raw(
            'UPDATE clinic_messages SET is_read=1 WHERE recipient_id=? AND is_read=0',
            [(int)$user['user_id']]
        );
        $api->respond(['message'=>'Messages marked as read.']);
    }

    public function settings()
    {
        [$api,$db,$user]=$this->ctx(['Admin']);
        if($_SERVER['REQUEST_METHOD']==='GET'){$rows=$db->raw('SELECT setting_key,setting_value FROM clinic_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_KEY_PAIR);$api->respond(['data'=>$rows]);}
        $api->require_method('PUT');$b=$api->body();$allowed=['clinic_name'=>120,'clinic_hours'=>255,'appointment_duration'=>3];
        foreach($allowed as $key=>$max){$value=trim((string)($b[$key]??''));if(mb_strlen($value)>$max||($key==='clinic_name'&&$value===''))$api->respond_error('Enter a clinic name and values within the allowed length.',422);if($key==='appointment_duration'&&(!ctype_digit($value)||(int)$value<5||(int)$value>600))$api->respond_error('Default appointment duration must be between 5 and 600 minutes.',422);$db->raw('INSERT INTO clinic_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)',[$key,$value,(int)$user['user_id']]);}
        medicare_audit($db,(int)$user['user_id'],'UPDATE','clinic_settings',null);$api->respond(['message'=>'Clinic settings saved.']);
    }

    public function toothChart($patientId)
    {
        [$api,$db,$user]=$this->ctx(['Staff','Admin']);$patientId=(int)$patientId;
        $patient=$db->raw('SELECT patient_id FROM patients WHERE patient_id=? AND is_archived=0',[$patientId])->fetch(PDO::FETCH_ASSOC);if(!$patient)$api->respond_error('Active patient record not found.',404);
        if($_SERVER['REQUEST_METHOD']==='GET'){$rows=$db->raw('SELECT tooth_no,condition_name,updated_at FROM tooth_conditions WHERE patient_id=? ORDER BY tooth_no',[$patientId])->fetchAll(PDO::FETCH_ASSOC);$api->respond(['data'=>$rows]);}
        $api->require_method('PUT');$b=$api->body();$tooth=(string)($b['tooth_no']??'');$condition=$b['condition_name']??'';
        if(!preg_match('/^(1[1-8]|2[1-8]|3[1-8]|4[1-8])$/',$tooth)||!in_array($condition,['Healthy','Decayed','Filled','Crown','Root Canal','Extracted'],true))$api->respond_error('Select a valid FDI tooth and condition.',422);
        $db->raw('INSERT INTO tooth_conditions(patient_id,tooth_no,condition_name,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE condition_name=VALUES(condition_name),updated_by=VALUES(updated_by)',[$patientId,$tooth,$condition,(int)$user['user_id']]);medicare_audit($db,(int)$user['user_id'],'UPDATE','tooth_conditions',$patientId);$api->respond(['message'=>'Tooth chart updated.']);
    }
}
