<?php
require_once '../../config.php';

$zoneId = $_GET['zone_id'] ?? '';

if (!$zoneId) {
    echo '<em>Select zone first</em>';
    exit();
}

$format = $_GET['format'] ?? 'checkbox';
$shuttles = $firestore
    ->collection('Shuttles')
    ->where('zone_id', '=', $zoneId)
    ->where('status', '=', 'active')
    ->documents();

$found = false;

if ($format === 'select') {
    echo '<option value="">-- Choose Shuttle --</option>';
}

foreach ($shuttles as $s) {
    $found = true;
    $shuttleId = $s->id();

    $driversSnap = $firestore
        ->collection('Staffs')
        ->where('role', '=', 'driver')
        ->where('assigned_shuttle_id', '=', $shuttleId)
        ->where('status', '=', 'active')
        ->documents();

    $driverNames = [];
    foreach ($driversSnap as $d) {
        $driverNames[] = $d->data()['full_name'] ?? 'Unnamed';
    }

    $label = empty($driverNames)
        ? 'No driver assigned'
        : implode(', ', $driverNames);

    if ($format === 'select') {
        echo '<option value="' . htmlspecialchars($shuttleId) . '">' . htmlspecialchars($shuttleId) . ' (' . htmlspecialchars($label) . ')</option>';
    } else {
        echo '<label style="display:block;margin-bottom:6px">';
        echo '<input type="checkbox" name="shuttles[]" value="' . htmlspecialchars($shuttleId) . '"> ';
        echo '<strong>' . htmlspecialchars($shuttleId) . '</strong> — Driver(s): ' . htmlspecialchars($label);
        echo '</label>';
    }
}

if (!$found) {
    if ($format === 'select') {
        echo '<option value="">No shuttles available</option>';
    } else {
        echo '<em>No shuttles available for this zone</em>';
    }
}
