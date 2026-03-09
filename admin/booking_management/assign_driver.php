<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

$bookingId = $_POST['booking_id'] ?? '';
$driverId = $_POST['driver_id'] ?? ''; // This will be empty if "Auto-Assign" is selected

if (!$bookingId) {
    header('Location: bookings_management.php?err=Invalid ID');
    exit();
}

$db = $firestore->database();

// ==========================================
// 1. AUTO-ASSIGN LOGIC (If no driver selected)
// ==========================================
if (empty($driverId)) {
    
    // A. Fetch Booking to get Location
    $bookingSnap = $db->collection('Bookings')->document($bookingId)->snapshot();
    if (!$bookingSnap->exists()) { header('Location: bookings_management.php?err=Booking Not Found'); exit(); }
    $booking = $bookingSnap->data();
    
    $pickupStopId = $booking['pickup_stop_id'] ?? '';

    // B. Find Zone of the Pickup Stop
    $zoneId = null;
    if ($pickupStopId) {
        $stopSnap = $db->collection('Stops')->document($pickupStopId)->snapshot();
        if ($stopSnap->exists()) {
            $zones = $stopSnap->data()['zone_ids'] ?? [];
            if (!empty($zones)) {
                $zoneId = $zones[0]; // Use the first linked zone
            }
        }
    }

    if (!$zoneId) {
        header('Location: bookings_management.php?err=Cannot Auto-Assign: Pickup Zone Unknown');
        exit();
    }

    // C. Find Shuttles in this Zone
    $shuttlesInZone = [];
    $shuttlesRef = $db->collection('Shuttles')->where('zone_id', '=', $zoneId)->documents();
    foreach ($shuttlesRef as $s) {
        $shuttlesInZone[] = $s->id();
    }

    if (empty($shuttlesInZone)) {
        header('Location: bookings_management.php?err=No shuttles operate in this zone');
        exit();
    }

    // D. Find Active Drivers assigned to those Shuttles
    // Note: Firestore WHERE 'in' queries are limited, so we loop or check manually if list is small.
    // Ideally: ->where('assigned_shuttle_id', 'in', $shuttlesInZone)
    
    $availableDrivers = [];
    $driversRef = $db->collection('Staffs')
        ->where('role', '=', 'driver')
        ->where('status', '=', 'active') // Only Online Drivers
        ->documents();

    foreach ($driversRef as $d) {
        $dData = $d->data();
        $assignedShuttle = $dData['assigned_shuttle_id'] ?? '';
        
        // Check if their shuttle is in the target zone
        if (in_array($assignedShuttle, $shuttlesInZone)) {
            $availableDrivers[] = $d->id();
        }
    }

    // E. Pick a Driver (Round Robin or Random)
    if (!empty($availableDrivers)) {
        // Simple Random Assignment for now
        $randomIndex = array_rand($availableDrivers);
        $driverId = $availableDrivers[$randomIndex];
    } else {
        header('Location: bookings_management.php?err=No available drivers found in Zone ' . $zoneId);
        exit();
    }
}

// ==========================================
// 2. ASSIGNMENT EXECUTION
// ==========================================
if ($bookingId && $driverId) {
    try {
        // Update Booking
        $db->collection('Bookings')->document($bookingId)->update([
            ['path' => 'driver_id', 'value' => $driverId],
            ['path' => 'status', 'value' => 'confirmed'], // Confirmed = Driver Accepted/Assigned
            ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
        ]);

        // Optional: Create a Notification for the Driver here
        // $db->collection('Notifications')->add([...]);

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