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

$db = $firestore->database();

// ==========================================
// 1. AUTO-ASSIGN LOGIC (If no driver selected)
// ==========================================
if (empty($driverId)) {
    $bookingSnap = $db->collection('Bookings')->document($bookingId)->snapshot();
    if (!$bookingSnap->exists()) { header('Location: bookings_management.php?err=Booking Not Found'); exit(); }
    $booking = $bookingSnap->data();
    
    $pickupStopId = $booking['pickup_stop_id'] ?? '';

    $zoneId = null;
    if ($pickupStopId) {
        $stopSnap = $db->collection('Stops')->document($pickupStopId)->snapshot();
        if ($stopSnap->exists()) {
            $zones = $stopSnap->data()['zone_ids'] ?? [];
            if (!empty($zones)) {
                $zoneId = $zones[0];
            }
        }
    }

    if (!$zoneId) {
        header('Location: bookings_management.php?err=Cannot Auto-Assign: Pickup Zone Unknown');
        exit();
    }

    $shuttlesInZone = [];
    $shuttlesRef = $db->collection('Shuttles')->where('zone_id', '=', $zoneId)->documents();
    foreach ($shuttlesRef as $s) {
        $shuttlesInZone[] = $s->id();
    }

    if (empty($shuttlesInZone)) {
        header('Location: bookings_management.php?err=No shuttles operate in this zone');
        exit();
    }
    
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
    
    $availableDrivers = [];
    $driversRef = $db->collection('Staffs')
        ->where('role', '=', 'driver')
        ->where('status', '=', 'active')
        ->documents();

    foreach ($driversRef as $d) {
        $dData = $d->data();
        $assignedShuttle = $dData['assigned_shuttle_id'] ?? '';
        
        // Ensure shuttle is in zone AND driver is not busy
        if (in_array($assignedShuttle, $shuttlesInZone) && !in_array($d->id(), $busyDrivers)) {
            $availableDrivers[] = $d->id();
        }
    }

    if (!empty($availableDrivers)) {
        $randomIndex = array_rand($availableDrivers);
        $driverId = $availableDrivers[$randomIndex];
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
        
        // Link Shuttle
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