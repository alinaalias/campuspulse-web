<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

// 1. Capture Inputs safely
$alert_type = trim($_POST['alert_type'] ?? '');
$detected_location = trim($_POST['detected_location'] ?? 'Unknown Location');
$lat = trim($_POST['lat'] ?? 'N/A');
$lng = trim($_POST['lng'] ?? 'N/A');

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

// 2. The Smart Mapping Logic (Waze Style)
$tag = '';
$title = '';
$message = '';
$lifespanHours = 2; // Default 2 hours

if ($alert_type === 'breakdown' || $alert_type === 'accident') {
    $tag = '#Emergency';
    $title = ucfirst($alert_type) . " Reported";
    $message = "URGENT: Driver {$driverName} has reported a(n) {$alert_type} near {$detected_location}. A replacement shuttle is being arranged. Expect delays.";
    $lifespanHours = 4; // Major incidents stay on the board longer

} elseif ($alert_type === 'traffic') {
    $tag = '#Warning';
    $title = "Heavy Traffic Delay";
    $message = "Driver {$driverName} reports heavy traffic near {$detected_location}. Shuttles are proceeding with caution and delays are expected.";
    $lifespanHours = 2; // Traffic usually clears up in a couple hours

} elseif ($alert_type === 'rain') {
    $tag = '#Warning';
    $title = "Severe Weather / Rain";
    $message = "Driver {$driverName} reports heavy rain near {$detected_location}. Driving at reduced speeds for safety.";
    $lifespanHours = 3;

} else {
    header('Location: driver_dashboard.php?msg=alert_failed_invalid_type');
    exit();
}

// Calculate Expiry Date
$now = time();
$expires_at = date('Y-m-d H:i:s', $now + ($lifespanHours * 3600));

// 3. Database Insertion
try {
    $db = $firestore->database();

    $document = [
        'title' => $title,
        'message' => $message,
        'tag' => $tag, // New Tagging System
        'status' => 'active', // INSTANT PUBLISH
        'author_id' => $driverId,
        'created_at' => date('Y-m-d H:i:s', $now),
        'schedule_time' => null,
        'expires_at' => $expires_at, // Auto-cleanup
        'location_name' => $detected_location,
        'location_lat' => $lat,
        'location_lng' => $lng
    ];

    $db->collection('Announcements')->add($document);

    header('Location: driver_dashboard.php?msg=alert_sent');
    exit();
} catch (Exception $e) {
    header('Location: driver_dashboard.php?msg=alert_failed_db');
    exit();
}
?>