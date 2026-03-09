<?php
require_once '../config.php'; // Load your Firestore setup

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['schedule_id'];
    $lat = (float)$_POST['lat'];
    $lng = (float)$_POST['lng'];

    // This updates the exact fields the Flutter app is listening to
    $firestore->database()->collection('Schedules')->document($id)->set([
        'current_lat' => $lat,
        'current_lng' => $lng,
        'last_updated' => new DateTime() // Optional: Timestamp
    ], ['merge' => true]); // MERGE is important so you don't delete other fields!

    echo json_encode(['status' => 'success']);
}
?>