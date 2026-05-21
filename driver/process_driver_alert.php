<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur'); // Prevent config timezone overrides

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$driverId = $_SESSION['user_id'];

$driverSnap = $firestore->collection('Staffs')->document($driverId)->snapshot();
$driverData = $driverSnap->data();

$todayDate = new DateTime('today');
$licExp = $driverData['license_expiry'] ?? '';
$psvExp = $driverData['psv_expiry'] ?? '';
$licDays = !empty($licExp) ? (int) $todayDate->diff(new DateTime($licExp))->format('%r%a') : null;
$psvDays = !empty($psvExp) ? (int) $todayDate->diff(new DateTime($psvExp))->format('%r%a') : null;
$isExpired = (($licDays !== null && $licDays < 0) || ($psvDays !== null && $psvDays < 0));
$status = $driverData['status'] ?? '';

if ($status === 'suspended' || $status === 'inactive' || $status === 'pending_review' || $isExpired) {
    $_SESSION['requires_compliance_update'] = true;
    header('Location: driver_profile.php');
    exit();
} else {
    unset($_SESSION['requires_compliance_update']);
}

// Capture Inputs safely
$alert_type = trim($_POST['alert_type'] ?? '');
$detected_location = trim($_POST['detected_location'] ?? 'Unknown Location');
$lat = trim($_POST['lat'] ?? 'N/A');
$lng = trim($_POST['lng'] ?? 'N/A');
$additional_message = trim($_POST['additional_message'] ?? '');

if (empty($lat))
    $lat = 'N/A';
if (empty($lng))
    $lng = 'N/A';

if (empty($alert_type)) {
    header('Location: driver_dashboard.php?msg=alert_failed_type_missing');
    exit();
}

$driverName = $_SESSION['full_name'] ?? 'Unknown Driver';
$driverId = $_SESSION['user_id'] ?? 'Unknown ID';
$db = $firestore;
$today = date('Y-m-d');

// Fetch Context Data (Which shuttle and schedule are affected?)
$shuttleId = '';
$scheduleId = '';
$brokenScheduleTime = '';

try {
    $driverDoc = $db->collection('Staffs')->document($driverId)->snapshot();
    if ($driverDoc->exists()) {
        $shuttleId = $driverDoc->data()['assigned_shuttle_id'] ?? '';
    }

    if (!empty($shuttleId)) {
        $schedules = $db->collection('Schedules')
            ->where('driver_id', '=', $driverId)
            ->documents();

        foreach ($schedules as $sch) {
            $sData = $sch->data();
            $schDate = $sData['date'] ?? '';
            $status = $sData['status'] ?? '';

            if ($schDate === $today && ($status === 'active' || $status === 'scheduled')) {
                $scheduleId = $sch->id();
                $brokenScheduleTime = $sData['departure_time'] ?? '';
                break; 
            }
        }
    }
} catch (Exception $e) {
    error_log("Driver Alert Schedule Fetch Error: " . $e->getMessage());
}

// The Smart Mapping Logic
$tag = '';
$title = '';
$message = '';
$lifespanHours = 2;

if ($alert_type === 'breakdown' || $alert_type === 'accident') {
    $tag = '#Emergency';
    $title = ucfirst($alert_type) . " Reported";
    $message = "URGENT: Driver {$driverName} has reported a(n) {$alert_type} near {$detected_location}. A replacement shuttle is being arranged. Expect delays.";
    $lifespanHours = 4;

    try {
        if (!empty($shuttleId)) {
            $db->collection('Shuttles')->document($shuttleId)->update([
                ['path' => 'status', 'value' => 'maintenance'],
                ['path' => 'is_online', 'value' => false],
                ['path' => 'job_status', 'value' => 'idle']
            ]);
        }
        $db->collection('Staffs')->document($driverId)->update([
            ['path' => 'duty_status', 'value' => 'offline']
        ]);
    } catch (Exception $e) {
    }

} elseif ($alert_type === 'traffic') {
    $tag = '#Warning';
    $title = "Heavy Traffic Delay";
    $message = "Driver {$driverName} reports heavy traffic near {$detected_location}. Shuttles are proceeding with caution and delays are expected.";
    $lifespanHours = 2;
} elseif ($alert_type === 'rain') {
    $tag = '#Warning';
    $title = "Severe Weather / Rain";
    $message = "Driver {$driverName} reports heavy rain near {$detected_location}. Driving at reduced speeds for safety.";
    $lifespanHours = 3;
} else {
    header('Location: driver_dashboard.php?msg=alert_failed_invalid_type');
    exit();
}

$now = time();
$expires_at = date('Y-m-d H:i:s', $now + ($lifespanHours * 3600));

// Database Insertion
try {
    $document = [
        'title' => $title,
        'message' => $message,
        'additional_message' => $additional_message,
        'tag' => $tag,
        'status' => 'active',
        'author_id' => $driverId,
        'created_at' => date('Y-m-d H:i:s', $now),
        'schedule_time' => null,
        'expires_at' => $expires_at,
        'location_name' => $detected_location,
        'location_lat' => $lat,
        'location_lng' => $lng,
        'shuttle_id' => $shuttleId,
        'schedule_id' => $scheduleId, 
        'broken_schedule_time' => $brokenScheduleTime 
    ];

    $db->collection('Announcements')->add($document);

    // THE FIX: Dispatch real-time push notification straight to Admin FCM topic
    try {
        if (isset($messaging)) {
            $topic = 'admin';
            $pushTitle = ($alert_type === 'breakdown' || $alert_type === 'accident') ? "🚨 EMERGENCY: " . $title : "⚠️ Alert: " . $title;
            $pushBody = $message;
            if (!empty($additional_message)) {
                $pushBody .= "\n\nDetails: " . $additional_message;
            }
            
            $notification = \Kreait\Firebase\Messaging\Notification::create($pushTitle, $pushBody);
            $cloudMessage = \Kreait\Firebase\Messaging\CloudMessage::withTarget('topic', $topic)->withNotification($notification);
            $messaging->send($cloudMessage);
        }
    } catch (Exception $messagingException) {
        error_log("FCM Admin Emergency Push Failed: " . $messagingException->getMessage());
    }

    header('Location: driver_dashboard.php?msg=alert_sent');
    exit();
} catch (Exception $e) {
    header('Location: driver_dashboard.php?msg=alert_failed_db');
    exit();
}
?>