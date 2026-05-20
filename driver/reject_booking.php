<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$driverId = $_SESSION['user_id'];
$bookingId = $_POST['booking_id'] ?? '';

if (empty($bookingId)) {
    echo json_encode(['success' => false, 'message' => 'Missing Booking ID']);
    exit();
}

$db = $firestore->database();

try {
    // 1. Fetch the Booking Data
    $bookingRef = $db->collection('Bookings')->document($bookingId);
    $bookingSnap = $bookingRef->snapshot();

    if (!$bookingSnap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit();
    }

    $bData = $bookingSnap->data();

    // Prevent double-rejections if someone else already handled it
    if ($bData['status'] !== 'searching') {
        echo json_encode(['success' => true, 'message' => 'Already handled']);
        exit();
    }

    // 2. Add current driver to 'rejected_by' list
    $rejectedBy = $bData['rejected_by'] ?? [];
    if (!in_array($driverId, $rejectedBy)) {
        $rejectedBy[] = $driverId;
    }

    $pickupLat = $bData['pickup_lat'] ?? null;
    $pickupLng = $bData['pickup_lng'] ?? null;

    // 3. Find the NEXT Nearest Driver
    $nextCandidateId = null;

    if ($pickupLat !== null && $pickupLng !== null) {
        $shortestDistance = 999999;

        // Fetch ALL available shuttles
        $shuttles = $db->collection('Shuttles')
            ->where('is_online', '=', true)
            ->where('job_status', '=', 'idle')
            ->documents();

        foreach ($shuttles as $sDoc) {
            $sData = $sDoc->data();
            $shuttleId = $sDoc->id();

            // Find who is driving this shuttle
            $potentialDriverId = null;
            $staffs = $db->collection('Staffs')
                ->where('role', '=', 'driver')
                ->where('assigned_shuttle_id', '=', $shuttleId)
                ->documents();

            foreach ($staffs as $stf) {
                $potentialDriverId = $stf->id();
                break;
            }

            // Skip if no driver, or if this driver has already rejected this booking!
            if (!$potentialDriverId || in_array($potentialDriverId, $rejectedBy)) {
                continue;
            }

            // Calculate Distance (Haversine formula in PHP)
            $sLat = $sData['current_lat'] ?? 0;
            $sLng = $sData['current_lng'] ?? 0;

            $theta = $pickupLng - $sLng;
            $dist = sin(deg2rad($pickupLat)) * sin(deg2rad($sLat)) + cos(deg2rad($pickupLat)) * cos(deg2rad($sLat)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $km = $miles * 1.609344;

            if ($km < $shortestDistance) {
                $shortestDistance = $km;
                $nextCandidateId = $potentialDriverId;
            }
        }
    }

    // 4. Update the Booking based on what we found
    if ($nextCandidateId !== null) {
        // Cascade to the next nearest driver
        $bookingRef->update([
            ['path' => 'candidate_driver_id', 'value' => $nextCandidateId],
            ['path' => 'rejected_by', 'value' => $rejectedBy]
        ]);
    } else {
        // NO DRIVERS LEFT! Send it to the Admin.
        $bookingRef->update([
            ['path' => 'candidate_driver_id', 'value' => null],
            ['path' => 'status', 'value' => 'admin_review'],
            ['path' => 'rejected_by', 'value' => $rejectedBy]
        ]);

        // Optional: Trigger a notification to the admin here if you want
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}