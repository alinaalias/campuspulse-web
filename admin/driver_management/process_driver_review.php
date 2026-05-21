<?php
session_start();
require_once '../../config.php';
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendSystemEmail($toEmail, $toName, $subject, $body)
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        // GMAIL CREDENTIALS
        $mail->Username = 'noralina2374@gmail.com';
        $mail->Password = 'qhke yeep evzd ubrn';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noralina2374@gmail.com', 'CampusPulse Admin');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = $_POST['id'] ?? '';
$action = $_POST['action'] ?? '';
$reason = $_POST['reason'] ?? '';

if (empty($id) || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    $db = $firestore;
    $driverRef = $db->collection('Staffs')->document($id);
    $snap = $driverRef->snapshot();

    if (!$snap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit();
    }

    $driverData = $snap->data();
    $driverEmail = $driverData['email'] ?? '';
    $driverName = $driverData['full_name'] ?? 'Driver';

    $updates = [];
    $adminName = $_SESSION['full_name'] ?? 'Admin';

    if ($action === 'approve') {
        $updates[] = ['path' => 'status', 'value' => 'active'];
        $updates[] = ['path' => 'last_status_change_reason', 'value' => 'Compliance Documents Approved'];
        
        if (!empty($driverEmail)) {
            $msg = "Dear $driverName,\n\nWe are pleased to inform you that your compliance credentials have been reviewed and approved.\nYou can now securely log in and access your dashboard to receive assignments.\n\nThank you,\nCampusPulse HR Fleet Team";
            sendSystemEmail($driverEmail, $driverName, "CampusPulse: Your Account is Active!", $msg);
        }
    } elseif ($action === 'reject') {
        $updates[] = ['path' => 'status', 'value' => 'suspended'];
        $reasonStr = 'Documents Rejected: ' . $reason;
        $updates[] = ['path' => 'last_status_change_reason', 'value' => $reasonStr];
        
        if (!empty($driverEmail)) {
            $msg = "Dear $driverName,\n\nYour recently uploaded credentials have been reviewed, but unfortunately could not be verified.\n\nReason for Rejection: $reason\n\nPlease log into your Driver Profile and re-upload the corrected documents so your account can be reactivated.\n\nThank you,\nCampusPulse HR Fleet Team";
            sendSystemEmail($driverEmail, $driverName, "CampusPulse: Action Required for Credentials", $msg);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
    }

    $updates[] = ['path' => 'last_status_change_admin', 'value' => $adminName];
    $updates[] = ['path' => 'last_status_change_at', 'value' => date('Y-m-d H:i:s')];

    $driverRef->update($updates);

    if ($action === 'approve') {
        $notifRef = $db->collection('Notifications')->newDocument();
        $notifRef->set([
            'user_id' => $id,
            'title' => 'Account Approved',
            'message' => 'Your recent document updates have been reviewed and approved. You can now access the dashboard.',
            'type' => 'success',
            'tag' => '#Account',
            'is_read' => false,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Driver review processed successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
