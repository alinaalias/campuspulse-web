<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') exit();

$driverId = $_SESSION['user_id'];
$ref = $firestore->database()->collection('Staffs')->document($driverId);
$snap = $ref->snapshot();

if ($snap->exists()) {
    $current = $snap->data()['status'] ?? 'inactive';
    $newStatus = ($current === 'active') ? 'inactive' : 'active';
    
    $ref->update([
        ['path' => 'status', 'value' => $newStatus]
    ]);
}
?>