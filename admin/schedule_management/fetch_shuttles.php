<?php
require_once '../../config.php';

$zoneId = $_GET['zone_id'] ?? '';

if (!$zoneId) {
    echo '<em>Select zone first</em>';
    exit();
}

$shuttles = $firestore->database()
    ->collection('Shuttles')
    ->where('zone_id', '=', $zoneId)
    ->where('status', '=', 'active')
    ->documents();

$found = false;

foreach ($shuttles as $s) {
    $found = true;
    $shuttleId = $s->id();

    $driversSnap = $firestore->database()
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

    echo '<label style="display:block;margin-bottom:6px">';
    echo '<input type="checkbox" name="shuttles[]" value="'.$shuttleId.'"> ';
    echo '<strong>'.$shuttleId.'</strong> — Driver(s): '.$label;
    echo '</label>';
}

if (!$found) {
    echo '<em>No shuttles available for this zone</em>';
}
