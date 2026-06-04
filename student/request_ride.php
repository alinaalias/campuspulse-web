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
function haversineDistance($lat1, $lon1, $lat2, $lon2)
{
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
    $db = $firestore;

    // Fetch Pickup Coordinates AND Zone ID
    $pickupSnap = $db->collection('Stops')->document($pickupStopId)->snapshot();

    if (!$pickupSnap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Invalid pick up location.']);
        exit();
    }

    $pickupData = $pickupSnap->data();
    $pickupLat = floatval($pickupData['lat'] ?? 0);
    $pickupLng = floatval($pickupData['lng'] ?? 0);
    $zoneIds = $pickupData['zone_ids'] ?? [];
    $zoneId = !empty($zoneIds) ? $zoneIds[0] : null;

    if (!$zoneId || $pickupLat === 0) {
        echo json_encode(['success' => false, 'message' => 'Location data incomplete. Cannot assign driver.']);
        exit();
    }

    // Phase 2: Secure Spatial Scan (Find Nearest Available Driver in the SAME Zone)
    $onlineShuttles = [];
    $shuttlesQuery = $db->collection('Shuttles')->where('is_online', '=', true)->documents();
    
    foreach ($shuttlesQuery as $sDoc) {
        if (!$sDoc->exists()) continue;
        $sData = $sDoc->data();
        
        // Filter in PHP memory to avoid Firebase Composite Index crashes
        $sZone = $sData['zone_id'] ?? '';
        $sJobStatus = strtolower($sData['job_status'] ?? '');
        $sStatus = strtolower($sData['status'] ?? '');
        
        if ($sZone === $zoneId && $sJobStatus === 'idle' && $sStatus === 'active') {
            $onlineShuttles[$sDoc->id()] = $sData;
        }
    }

    $driverMap = []; 
    $staffs = $db->collection('Staffs')->where('role', '=', 'driver')->documents();
    foreach ($staffs as $stf) {
        if ($stf->exists()) {
            $sId = $stf->data()['assigned_shuttle_id'] ?? '';
            if (!empty($sId)) $driverMap[$sId] = $stf->id();
        }
    }

    $busyDrivers = [];
    $activeJobs = $db->collection('Bookings')->where('status', 'in', ['confirmed', 'arriving', 'arrived', 'onboard'])->documents();
    foreach ($activeJobs as $job) {
        if (!empty($job->data()['driver_id'])) $busyDrivers[] = $job->data()['driver_id'];
    }
    
    $activeScheds = $db->collection('Schedules')->where('status', '=', 'active')->documents();
    foreach ($activeScheds as $sched) {
        if (!empty($sched->data()['driver_id'])) $busyDrivers[] = $sched->data()['driver_id'];
    }

    $closestShuttleId = null;
    $candidateDriverId = null;
    $shortestDistance = PHP_FLOAT_MAX;

    foreach ($onlineShuttles as $shuttleId => $sData) {
        if (isset($driverMap[$shuttleId])) {
            $dId = $driverMap[$shuttleId];
            if (!in_array($dId, $busyDrivers)) {
                $sLat = floatval($sData['current_lat'] ?? 0);
                $sLng = floatval($sData['current_lng'] ?? 0);
                
                $dist = haversineDistance($pickupLat, $pickupLng, $sLat, $sLng);
                if ($dist < $shortestDistance) {
                    $shortestDistance = $dist;
                    $closestShuttleId = $shuttleId;
                    $candidateDriverId = $dId;
                }
            }
        }
    }

    // Phase 4: Driver Resolution & Booking Injection
    $bookingId = 'BOOK' . strtoupper(uniqid());
    $nowStr = date('Y-m-d H:i:s');
    
    if ($closestShuttleId && $candidateDriverId) {
        // Driver found! Ping them immediately.
        $db->collection('Bookings')->document($bookingId)->set([
            'booking_id' => $bookingId,
            'user_id' => $userId,
            'type' => 'ondemand',
            'zone_id' => $zoneId,
            'pickup_stop_id' => $pickupStopId,
            'dropoff_stop_id' => $dropoffStopId,
            'candidate_driver_id' => $candidateDriverId,
            'shuttle_id' => $closestShuttleId,
            'status' => 'searching', // Triggers driver UI
            'request_time' => $nowStr,
            'created_at' => $nowStr,
            'fare' => 1.50 
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Matchmaking successful. Pinging nearest driver...',
            'booking_id' => $bookingId
        ]);
    } else {
        // No driver available right now. Queue it up as pending so Admin sees it!
        $db->collection('Bookings')->document($bookingId)->set([
            'booking_id' => $bookingId,
            'user_id' => $userId,
            'type' => 'ondemand',
            'zone_id' => $zoneId,
            'pickup_stop_id' => $pickupStopId,
            'dropoff_stop_id' => $dropoffStopId,
            'status' => 'pending', 
            'request_time' => $nowStr,
            'created_at' => $nowStr,
            'fare' => 1.50 
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Added to queue. Waiting for an available driver.',
            'booking_id' => $bookingId
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Backend synchronization error: ' . $e->getMessage()]);
}