<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$id = $_GET['id'] ?? '';

if ($id) {
    try {
        $firestore->database()->collection('Bookings')->document($id)->update([
            ['path' => 'status', 'value' => 'cancelled'],
            ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
        ]);
        header('Location: bookings_management.php?msg=Booking Cancelled');
    } catch (Exception $e) {
        header('Location: bookings_management.php?msg=Error Cancelling');
    }
} else {
    header('Location: bookings_management.php');
}
exit();
?>