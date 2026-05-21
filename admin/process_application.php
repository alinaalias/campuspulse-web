<?php
require_once '../config.php';
session_start();

require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Support both GET (Approve link) and POST (Reject form)
$applicationId = $_REQUEST['id'] ?? '';
$action = $_REQUEST['action'] ?? '';

if (empty($applicationId) || empty($action)) {
    $_SESSION['error'] = 'Invalid request parameters.';
    header('Location: admin_review_drivers.php');
    exit();
}

function migrateApplicationFiles($driverId, $appData, $bucket)
{
    $newPaths = [];

    // Configuration for standardized naming and folders
    $migrationMap = [
        'doc_profile_pic' => [
            'folder' => 'driver_profilepics',
            'name' => "driver_{$driverId}", // e.g. driver_DRV001
            'db_key' => 'profile_pic'
        ],
        'doc_license' => [
            'folder' => 'driver_credentials',
            'name' => "{$driverId}_license", // e.g. DRV001_license
            'db_key' => 'license_pic'
        ],
        'doc_psv' => [
            'folder' => 'driver_credentials',
            'name' => "{$driverId}_psv", // e.g. DRV001_psv
            'db_key' => 'psv_pic'
        ]
    ];

    foreach ($migrationMap as $sourceKey => $cfg) {
        if (!empty($appData[$sourceKey])) {
            $sourcePath = $appData[$sourceKey];
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);

            // Build permanent path: folder/standardized_name.extension
            $newPath = $cfg['folder'] . "/" . $cfg['name'] . "." . $extension;

            try {
                $sourceObject = $bucket->object($sourcePath);
                if ($sourceObject->exists()) {
                    // CORRECT SYNTAX: 
                    // 1st arg: The destination bucket object
                    // 2nd arg: Options array specifying the new path ('name')
                    $sourceObject->copy($bucket, ['name' => $newPath]);

                    $newPaths[$cfg['db_key']] = $newPath;
                }
            } catch (Exception $e) {
                error_log("File Migration Error for {$driverId}: " . $e->getMessage());
            }
        }
    }
    return $newPaths;
}
/**
 * HELPER FUNCTION: Send Email via Gmail SMTP
 */
function sendSystemEmail($toEmail, $toName, $subject, $body)
{
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        // YOUR GMAIL CREDENTIALS HERE
        $mail->Username = 'noralina2374@gmail.com';
        $mail->Password = 'qhke yeep evzd ubrn';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('noralina2374@gmail.com', 'CampusPulse Admin');
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(false); // Sending plain text for simplicity
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

try {
    $db = $firestore;
    $docRef = $db->collection('DriverApplications')->document($applicationId);
    $snapshot = $docRef->snapshot();

    if (!$snapshot->exists()) {
        $_SESSION['error'] = 'Application not found.';
        header('Location: admin_review_drivers.php');
        exit();
    }

    $appData = $snapshot->data();
    $applicantEmail = $appData['email'] ?? '';
    $applicantName = $appData['full_name'] ?? 'Applicant';

    if ($action === 'reject') {
        // --- NEW LOGIC: Capture the Reason ---
        $presetReason = $_POST['reject_reason_preset'] ?? 'Did not meet requirements';
        $customReason = $_POST['reject_reason_custom'] ?? '';

        $finalReason = ($presetReason === 'custom' && !empty($customReason)) ? $customReason : $presetReason;

        // Update application status
        $docRef->update([
            ['path' => 'status', 'value' => 'rejected'],
            ['path' => 'reject_reason', 'value' => $finalReason], // Store it in DB for records
            ['path' => 'processed_at', 'value' => date('c')]
        ]);

        // Send Rejection Email using PHPMailer
        if (!empty($applicantEmail)) {
            $subject = "Update on your CampusPulse Driver Application";
            $message = "Dear $applicantName,\n\n";
            $message .= "Thank you for applying to join the CampusPulse fleet.\n\n";
            $message .= "After careful review, we regret to inform you that we are unable to proceed with your application at this time.\n\n";
            $message .= "Reason for rejection:\n- $finalReason\n\n";
            $message .= "If you believe this is an error or wish to update your documents, please reply to this email or reapply.\n\n";
            $message .= "Best regards,\nThe CampusPulse Admin Team";

            sendSystemEmail($applicantEmail, $applicantName, $subject, $message);
        }

        $_SESSION['success'] = "Application from $applicantName has been rejected.";

    } elseif ($action === 'approve') {
        $icNumber = $appData['ic_number'] ?? '';
        $existingStaff = $db->collection('Staffs')->where('ic_number', '=', $icNumber)->documents();

        if (!$existingStaff->isEmpty()) {
            $_SESSION['error'] = "Cannot approve: A driver with this IC Number already exists in the system.";

            // Optional: Auto-reject the duplicate application
            $docRef->update([
                ['path' => 'status', 'value' => 'rejected'],
                ['path' => 'reject_reason', 'value' => 'Duplicate Application Detected'],
                ['path' => 'processed_at', 'value' => date('c')]
            ]);

            header('Location: admin_review_drivers.php');
            exit();
        }

        // Step A: Generate new driver ID (DRVXXX)
        $counterRef = $db->collection('Counters')->document('drivers');
        $counterSnap = $counterRef->snapshot();

        $lastNumber = $counterSnap->exists() ? ($counterSnap['last_number'] ?? 0) : 0;
        $nextNumber = $lastNumber + 1;

        $counterRef->set(['last_number' => $nextNumber], ['merge' => true]);

        $driverId = 'DRV' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        $permanentFilePaths = migrateApplicationFiles($driverId, $appData, $bucket);

        // Step B: Staff Onboarding
        $icNumber = $appData['ic_number'] ?? '';
        $icClean = str_replace('-', '', $icNumber);
        $hashedPassword = password_hash($icClean, PASSWORD_DEFAULT);

        $newStaffData = [
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'email' => $applicantEmail,
            'full_name' => $applicantName,
            'ic_number' => $icNumber,
            'license_number' => $appData['license_number'] ?? '',
            'password' => $hashedPassword,
            'phone_number' => $appData['phone_number'] ?? '',
            'profile_pic' => $permanentFilePaths['profile_pic'] ?? ($appData['doc_profile_pic'] ?? ''),
            'license_pic' => $permanentFilePaths['license_pic'] ?? ($appData['doc_license'] ?? ''),
            'psv_pic' => $permanentFilePaths['psv_pic'] ?? ($appData['doc_psv'] ?? ''),
            'role' => 'driver',
            'status' => 'active',
            'gender' => $appData['gender'] ?? '',
            'dob' => $appData['dob'] ?? '',
            'home_address' => $appData['home_address'] ?? '',
            'license_expiry' => $appData['license_expiry'] ?? '',
            'psv_expiry' => $appData['psv_expiry'] ?? '',
            'years_experience' => $appData['years_experience'] ?? 0
        ];

        // Create document in Staffs collection using the generated ID
        $db->collection('Staffs')->document($driverId)->set($newStaffData);

        // Step C: Update Application Status
        $docRef->update([
            ['path' => 'status', 'value' => 'accepted'],
            ['path' => 'processed_at', 'value' => date('c')],
            ['path' => 'assigned_driver_id', 'value' => $driverId]
        ]);

        // Step D: Onboarding Email using PHPMailer
        if (!empty($applicantEmail)) {
            $portalUrl = "http://localhost/FYP/login.php";

            $subject = "Welcome to the CampusPulse Fleet!";
            $message = "Dear $applicantName,\n\n";
            $message .= "Congratulations! Your application to become a CampusPulse shuttle driver has been APPROVED.\n\n";
            $message .= "Here is your onboarding information:\n";
            $message .= "- Your Driver ID: $driverId\n";
            $message .= "- Portal Access: $portalUrl\n";
            $message .= "- Login Email: $applicantEmail\n";
            $message .= "- Temporary Password: Your IC Number WITHOUT dashes (e.g., if IC is 900101-14-5555, password is 900101145555).\n\n";
            $message .= "*** IMPORTANT: Please change your password immediately upon your first login. ***\n\n";
            $message .= "We look forward to working with you to improve our campus transit system.\n\n";
            $message .= "Best regards,\nThe CampusPulse Admin Team";

            sendSystemEmail($applicantEmail, $applicantName, $subject, $message);
        }

        $_SESSION['success'] = "Application approved! $applicantName has been registered as Driver ($driverId).";

    } else {
        $_SESSION['error'] = 'Invalid action specified.';
    }

} catch (Exception $e) {
    $_SESSION['error'] = "An error occurred: " . $e->getMessage();
}

header('Location: admin_review_drivers.php');
exit();
?>