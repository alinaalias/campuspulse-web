<?php
session_start();
require_once '../config.php';

// Driver Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    exit('Unauthorized');
}

$id = $_GET['id'] ?? '';
$status = $_GET['status'] ?? '';

// Allowed transitions
$validStatuses = ['arriving', 'onboard', 'completed'];

if ($id && in_array($status, $validStatuses)) {
    // Update Database
    $firestore->database()->collection('Bookings')->document($id)->update([
        ['path' => 'status', 'value' => $status],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ]);
}

// Redirect back to Dashboard
header('Location: driver_dashboard.php');
exit();
?>