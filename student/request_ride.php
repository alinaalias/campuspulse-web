<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

// Security Context: Assume student's ID relies on session
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in first.']);
    exit();
}

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Phase 1: Input Validation
$pickupStopId = $_POST['pickup_stop_id'] ?? '';
$dropoffStopId = $_POST['dropoff_stop_id'] ?? '';

if (empty($pickupStopId) || empty($dropoffStopId)) {
    echo json_encode(['success' => false, 'message' => 'Pickup and Dropoff locations are required.']);
    exit();
}

// Phase 3: The Haversine Distance Matrix (Helper)
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // Radius of Earth in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
        
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

try {
    $db = $firestore->database();

    // Fetch Pickup Coordinates
    $pickupSnap = $db->collection('Stops')->document($pickupStopId)->snapshot();

    if (!$pickupSnap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Invalid pick up location.']);
        exit();
    }

    $pickupData = $pickupSnap->data();
    $pickupLat = $pickupData['lat'] ?? null;
    $pickupLng = $pickupData['lng'] ?? null;

    if ($pickupLat === null || $pickupLng === null) {
        echo json_encode(['success' => false, 'message' => 'Coordinates for the pickup location are unavailable.']);
        exit();
    }

    // Phase 2: The Spatial Scan (Filtering Shuttles)
    // 1. is_online == true
    // 2. job_status == 'Idle'
    $shuttlesQuery = $db->collection('Shuttles')
        ->where('is_online', '=', true)
        ->where('job_status', '=', 'Idle')
        ->documents();

    $closestShuttleId = null;
    $shortestDistance = PHP_FLOAT_MAX;

    foreach ($shuttlesQuery as $shuttle) {
        $shuttleData = $shuttle->data();
        $shuttleLat = $shuttleData['current_lat'] ?? null;
        $shuttleLng = $shuttleData['current_lng'] ?? null;

        if ($shuttleLat !== null && $shuttleLng !== null) {
            $dist = haversineDistance($pickupLat, $pickupLng, $shuttleLat, $shuttleLng);
            
            if ($dist < $shortestDistance) {
                $shortestDistance = $dist;
                $closestShuttleId = $shuttle->id();
            }
        }
    }

    // Fallback: If no shuttles matched or returned a valid distance
    if (!$closestShuttleId) {
        echo json_encode(['success' => false, 'message' => 'No shuttles currently available.']);
        exit();
    }

    // Phase 4: Driver Resolution & Booking Injection
    $staffQuery = $db->collection('Staffs')
        ->where('role', '=', 'driver')
        ->where('assigned_shuttle_id', '=', $closestShuttleId)
        ->where('duty_status', '=', 'online')
        ->limit(1)
        ->documents();

    $candidateDriverId = null;
    foreach ($staffQuery as $staff) {
        $candidateDriverId = $staff->id();
    }

    if (!$candidateDriverId) {
        echo json_encode(['success' => false, 'message' => 'Target shuttle found but driver is marked offline. No drivers available.']);
        exit();
    }

    // Generate Booking Payload
    $bookingId = 'BOOK' . strtoupper(uniqid()); 

    $db->collection('Bookings')->document($bookingId)->set([
        'booking_id' => $bookingId,
        'user_id' => $userId,
        'type' => 'ondemand',
        'pickup_stop_id' => $pickupStopId,
        'dropoff_stop_id' => $dropoffStopId,
        'candidate_driver_id' => $candidateDriverId,
        'shuttle_id' => $closestShuttleId,
        'status' => 'searching',  // Crucial trigger for the driver's UI onSnapshot listener
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Success Output
    echo json_encode([
        'success' => true,
        'message' => 'Matchmaking successful. Finding driver...',
        'booking_id' => $bookingId,
        'distance_m' => round($shortestDistance),
        'shuttle_id' => $closestShuttleId
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Backend synchronization error: ' . $e->getMessage()]);
}
