<?php
require_once '../../config.php';

$id = $_GET['id'] ?? '';

if (!$id) {
    header('Location: drivers_management.php?msg=error');
    exit();
}

$driverRef = $firestore->collection('Staffs')->document($id);
$snapshot = $driverRef->snapshot();

if (!$snapshot->exists()) {
    header('Location: drivers_management.php?msg=notfound');
    exit();
}

$driver = $snapshot->data();

// Only allow deletion if driver is inactive AND has no shuttle assigned
if ((isset($driver['status']) && $driver['status'] === 'inactive') || empty($driver['status'])) {
    
    // Strict HR Block: Prevent deleting if they control a physical vehicle
    if (!empty($driver['assigned_shuttle_id'])) {
        header('Location: drivers_management.php?msg=assigned_error');
        exit();
    }

    $driverRef->delete(); // Permanently delete
    header('Location: drivers_management.php?msg=deleted');
    exit();
} else {
    // Driver is still active — cannot delete
    header('Location: drivers_management.php?msg=active');
    exit();
}
