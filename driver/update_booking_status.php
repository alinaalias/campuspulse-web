<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    exit('Unauthorized');
}

$id = $_GET['id'] ?? '';
$status = $_GET['status'] ?? '';
$driverId = $_SESSION['user_id'];

$validStatuses = ['arriving', 'onboard', 'completed', 'cancelled'];

if ($id && in_array($status, $validStatuses)) {
    $db = $firestore->database();

    // 1. Update the Booking
    $db->collection('Bookings')->document($id)->update([
        ['path' => 'status', 'value' => $status],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ]);

    // 2. If 'completed', check if we need to set the shuttle to IDLE
    if ($status === 'completed') {
        // Find if this driver has ANY other 'onboard' or 'confirmed' bookings
        $activeCheck = $db->collection('Bookings')
            ->where('driver_id', '==', $driverId)
            ->where('status', 'in', ['confirmed', 'arriving', 'onboard'])
            ->documents();

        // If no other active jobs remain in the pool, set shuttle to Idle
        if ($activeCheck->isEmpty()) {
            $staffSnap = $db->collection('Staffs')->document($driverId)->snapshot();
            $shuttleId = $staffSnap->data()['assigned_shuttle_id'] ?? null;

            if ($shuttleId) {
                $db->collection('Shuttles')->document($shuttleId)->update([
                    ['path' => 'job_status', 'value' => 'Idle']
                ]);
            }
        }
    }
}

// 3. SMART REDIRECT: Go back to where the driver came from
$referrer = $_SERVER['HTTP_REFERER'] ?? 'driver_dashboard.php';
header("Location: " . $referrer);
exit();