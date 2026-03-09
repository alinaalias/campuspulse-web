<?php
require_once '../../config.php';

$zoneId = $_GET['zone_id'] ?? '';

if (!$zoneId) {
    echo '<option value="">Select zone first</option>';
    exit();
}

$routes = $firestore->database()
    ->collection('Routes')
    ->where('zone_id', '=', $zoneId)
    ->where('status', '=', 'active')
    ->documents();

echo '<option value="">-- Select Route --</option>';

foreach ($routes as $r) {
    echo '<option value="'.$r->id().'">'
        . htmlspecialchars($r->data()['route_name'])
        . '</option>';
}
