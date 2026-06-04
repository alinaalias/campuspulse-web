<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$bookingId = $_POST['booking_id'] ?? '';
$driverId = $_POST['driver_id'] ?? '';

if (!$bookingId) {
    header('Location: bookings_management.php?err=Invalid ID');
    exit();
}

$db = $firestore;

// ==========================================
// 1. AUTO-ASSIGN LOGIC (If no driver selected)
// ==========================================
if (empty($driverId)) {
    $bookingSnap = $db->collection('Bookings')->document($bookingId)->snapshot();
    if (!$bookingSnap->exists()) { header('Location: bookings_management.php?err=Booking Not Found'); exit(); }
    $booking = $bookingSnap->data();
    
    $pickupStopId = $booking['pickup_stop_id'] ?? '';

    $zoneId = null;
    $pickupLat = 0;
    $pickupLng = 0;
    
    if ($pickupStopId) {
        $stopSnap = $db->collection('Stops')->document($pickupStopId)->snapshot();
        if ($stopSnap->exists()) {
            $stopData = $stopSnap->data();
            $zones = $stopData['zone_ids'] ?? [];
            if (!empty($zones)) {
                $zoneId = $zones[0];
            }
            $pickupLat = floatval($stopData['lat'] ?? 0);
            $pickupLng = floatval($stopData['lng'] ?? 0);
        }
    }

    if (!$zoneId) {
        header('Location: bookings_management.php?err=Cannot Auto-Assign: Pickup Zone Unknown');
        exit();
    }

    // Step 1: Find all Online Shuttles in the Pickup Zone
    $onlineShuttles = [];
    $shuttlesQuery = $db->collection('Shuttles')->where('is_online', '=', true)->documents();
    foreach ($shuttlesQuery as $sDoc) {
        if ($sDoc->exists()) {
            $sData = $sDoc->data();
            if (($sData['zone_id'] ?? '') === $zoneId) { 
                $onlineShuttles[$sDoc->id()] = $sData;
            }
        }
    }

    // Step 2: Map drivers to those shuttles
    $driverMap = []; 
    $staffs = $db->collection('Staffs')->where('role', '=', 'driver')->documents();
    foreach ($staffs as $stf) {
        if ($stf->exists()) {
            $sId = $stf->data()['assigned_shuttle_id'] ?? '';
            if (!empty($sId)) {
                $driverMap[$sId] = $stf->id();
            }
        }
    }

    // Step 3: Find drivers currently busy with other active trips
    $busyDrivers = [];
    $activeJobs = $db->collection('Bookings')
        ->where('status', 'in', ['confirmed', 'arriving', 'arrived', 'onboard'])
        ->documents();
    foreach ($activeJobs as $job) {
        if (!empty($job->data()['driver_id'])) {
            $busyDrivers[] = $job->data()['driver_id'];
        }
    }
    
    $activeScheds = $db->collection('Schedules')
        ->where('status', '=', 'active')
        ->documents();
    foreach ($activeScheds as $sched) {
        if (!empty($sched->data()['driver_id'])) {
            $busyDrivers[] = $sched->data()['driver_id'];
        }
    }

    // Step 4: Calculate distance and find the nearest available driver
    $nearestDriverId = null;
    $shortestDistance = PHP_FLOAT_MAX;

    foreach ($onlineShuttles as $shuttleId => $sData) {
        if (isset($driverMap[$shuttleId])) {
            $dId = $driverMap[$shuttleId];
            if (!in_array($dId, $busyDrivers)) {
                $sLat = floatval($sData['current_lat'] ?? 0);
                $sLng = floatval($sData['current_lng'] ?? 0);
                
                // Calculate Euclidean distance to the pickup point
                $dist = pow($sLat - $pickupLat, 2) + pow($sLng - $pickupLng, 2);
                
                if ($dist < $shortestDistance) {
                    $shortestDistance = $dist;
                    $nearestDriverId = $dId;
                }
            }
        }
    }

    if ($nearestDriverId) {
        // Set the driverId to the nearest driver to trigger the hard-assignment below
        $driverId = $nearestDriverId;
    } else {
        header('Location: bookings_management.php?err=No idle drivers found in Zone ' . $zoneId);
        exit();
    }
}

// ==========================================
// 2. ASSIGNMENT EXECUTION
// ==========================================
if ($bookingId && $driverId) {
    try {
        $nowStr = date('Y-m-d H:i:s');
        
        $driverSnap = $db->collection('Staffs')->document($driverId)->snapshot();
        $shuttleId = $driverSnap->data()['assigned_shuttle_id'] ?? '';

        $db->collection('Bookings')->document($bookingId)->update([
            ['path' => 'driver_id', 'value' => $driverId],
            ['path' => 'shuttle_id', 'value' => $shuttleId],
            ['path' => 'status', 'value' => 'confirmed'], 
            ['path' => 'updated_at', 'value' => $nowStr]
        ]);
        
        if ($shuttleId) {
             $db->collection('Shuttles')->document($shuttleId)->update([
                 ['path' => 'job_status', 'value' => 'in job']
             ]);
        }

        header('Location: bookings_management.php?msg=Driver Assigned Successfully');
        exit();

    } catch (Exception $e) {
        header('Location: bookings_management.php?err=Database Error: ' . $e->getMessage());
        exit();
    }
}

header('Location: bookings_management.php?err=Assignment Failed');
exit();
?>