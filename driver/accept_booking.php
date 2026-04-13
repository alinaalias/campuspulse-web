<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

header('Content-Type: application/json');

// Security Context
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized Context']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid Request Method']);
    exit();
}

$bookingId = $_POST['booking_id'] ?? '';
$driverId = $_SESSION['user_id'] ?? '';
$shuttleId = $_SESSION['assigned_shuttle_id'] ?? '';

if (empty($bookingId) || empty($driverId) || empty($shuttleId)) {
    echo json_encode(['success' => false, 'message' => 'Missing session or booking references.']);
    exit();
}

try {
    $db = $firestore->database();
    $bookingRef = $db->collection('Bookings')->document($bookingId);
    $shuttleRef = $db->collection('Shuttles')->document($shuttleId);

    // Atomic Execution Flow
    $db->runTransaction(function ($transaction) use ($bookingRef, $shuttleRef, $driverId, $shuttleId) {
        // Read Booking Target
        $snap = $transaction->snapshot($bookingRef);
        if (!$snap->exists()) {
            throw new Exception("Booking ticket no longer exists.");
        }
        
        $bData = $snap->data();
        
        // Race Condition Validation Shield
        if (($bData['status'] ?? '') !== 'searching') {
            throw new Exception("Ride already accepted by another driver or expired.");
        }

        // Write Phase (Multi-Document Update)
        $transaction->update($bookingRef, [
            ['path' => 'status', 'value' => 'confirmed'],
            ['path' => 'driver_id', 'value' => $driverId],
            ['path' => 'shuttle_id', 'value' => $shuttleId],
            ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
        ]);

        $transaction->update($shuttleRef, [
            ['path' => 'job_status', 'value' => 'In Job'],
            ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
        ]);
    });

    echo json_encode(['success' => true, 'message' => 'Booking Handshake Accepted Securely']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
