<?php
session_start();
require_once '../config.php';

// Lets driver_profile.php notice when an admin unlocks or suspends the account.
// The page used to listen to the driver's Staffs document directly, but the Firestore
// rules keep Staffs private because it stores password hashes, so the check goes
// through the server, which reads with the Admin SDK.

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $driverSnap = $firestore->collection('Staffs')->document($_SESSION['user_id'])->snapshot();

    if (!$driverSnap->exists()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit;
    }

    $driverData = $driverSnap->data();

    // A fingerprint of the whole record, so the page can react to any change, such as a
    // renewed licence that leaves the status untouched, without receiving the record.
    echo json_encode([
        'success' => true,
        'status' => $driverData['status'] ?? '',
        'version' => sha1(json_encode($driverData, JSON_PARTIAL_OUTPUT_ON_ERROR)),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
