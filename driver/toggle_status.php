<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver')
    exit();

$driverId = $_SESSION['user_id'];
$ref = $firestore->database()->collection('Staffs')->document($driverId);
$snap = $ref->snapshot();

if ($snap->exists()) {
    // Default to offline if it doesn't exist yet
    $current = $snap->data()['duty_status'] ?? 'offline';
    $newStatus = ($current === 'online') ? 'offline' : 'online';

    $ref->update([
        ['path' => 'duty_status', 'value' => $newStatus]
    ]);
}
?>