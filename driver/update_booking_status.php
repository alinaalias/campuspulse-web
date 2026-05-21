<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    exit('Unauthorized');
}

$id = $_GET['id'] ?? '';
$status = $_GET['status'] ?? '';
$driverId = $_SESSION['user_id'];

// THE FIX 2: Added 'arrived' to the list of valid statuses
$validStatuses = ['arriving', 'arrived', 'onboard', 'completed', 'cancelled'];

if ($id && in_array($status, $validStatuses)) {
    $db = $firestore;

    // 1. Update the Booking
    $updateFields = [
        ['path' => 'status', 'value' => $status],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ];

    if ($status === 'onboard') {
        $updateFields[] = ['path' => 'check_in_time', 'value' => date('Y-m-d H:i:s')];
    }

    $db->collection('Bookings')->document($id)->update($updateFields);

    // 2. If 'completed', check if we need to set the shuttle to IDLE
    if ($status === 'completed' || $status === 'cancelled') {
        // Find if this driver has ANY other 'onboard' or 'confirmed' bookings
        $activeCheck = $db->collection('Bookings')
            ->where('driver_id', '=', $driverId)
            ->where('status', 'in', ['confirmed', 'arriving', 'onboard'])
            ->documents();

        // If no other active jobs remain in the pool, set shuttle to Idle
        if (empty($activeCheck)) {
            $staffSnap = $db->collection('Staffs')->document($driverId)->snapshot();
            $shuttleId = $staffSnap->data()['assigned_shuttle_id'] ?? null;

            if ($shuttleId) {
                try {
                    $db->collection('Shuttles')->document($shuttleId)->update([
                        ['path' => 'job_status', 'value' => 'idle']
                    ]);
                } catch (Exception $e) {
                }
            }
        }

        // --- CLEAR CURRENT TRIP ID ---
        try {
            $db->collection('Staffs')->document($driverId)->update([
                ['path' => 'current_trip_id', 'value' => '']
            ]);
        } catch (Exception $e) {
        }
    }
}

// 3. SMART REDIRECT: Go back to where the driver came from
$referrer = $_SERVER['HTTP_REFERER'] ?? 'driver_dashboard.php';
header("Location: " . $referrer);
exit();