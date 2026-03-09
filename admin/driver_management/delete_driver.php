<?php
require_once '../../config.php';

$id = $_GET['id'] ?? '';

if (!$id) {
    header('Location: drivers_management.php?msg=error');
    exit();
}

$driverRef = $firestore->database()->collection('Staffs')->document($id);
$snapshot = $driverRef->snapshot();

if (!$snapshot->exists()) {
    header('Location: drivers_management.php?msg=notfound');
    exit();
}

$driver = $snapshot->data();

// Only allow deletion if driver is inactive
if (isset($driver['status']) && $driver['status'] === 'inactive') {
    $driverRef->delete(); // Permanently delete
    header('Location: drivers_management.php?msg=deleted');
    exit();
} else {
    // Driver is still active — cannot delete
    header('Location: drivers_management.php?msg=active');
    exit();
}
