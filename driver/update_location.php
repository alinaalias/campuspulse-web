<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $lat = (float) $_POST['lat'];
    $lng = (float) $_POST['lng'];

    // 1. Update Schedule (If this is a fixed route)
    if (!empty($_POST['schedule_id'])) {
        $firestore->database()->collection('Schedules')->document($_POST['schedule_id'])->set([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'last_updated' => new DateTime()
        ], ['merge' => true]);
    }

    // 2. Update Shuttle (This is what the Live Operations map uses!)
    if (!empty($_POST['shuttle_id'])) {
        $firestore->database()->collection('Shuttles')->document($_POST['shuttle_id'])->set([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'is_online' => true,
            'last_updated' => new DateTime()
        ], ['merge' => true]);
    }

    echo json_encode(['status' => 'success']);
}