<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$scheduleId = $_GET['id'] ?? '';
if (empty($scheduleId)) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit();
}

try {
    $db = $firestore;
    $schedRef = $db->collection('Schedules')->document($scheduleId);
    $schedSnap = $schedRef->snapshot();

    if (!$schedSnap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found']);
        exit();
    }

    $tripData = $schedSnap->data();
    $bookedCount = $tripData['booked_count'] ?? 0;
    $capacity = $tripData['capacity'] ?? 13;
    $etas = $tripData['etas'] ?? [];
    $stops = [];

    if (!empty($etas)) {
        asort($etas);
        foreach ($etas as $sid => $time) {
            $sSnap = $db->collection('Stops')->document($sid)->snapshot();
            $sName = $sSnap->exists() ? ($sSnap->data()['name'] ?? $sSnap->data()['stop_name'] ?? $sid) : $sid;
            $stops[] = [
                'name' => $sName,
                'time' => $time
            ];
        }
    } else {
        // Fallback for static routes
        $routeRef = $db->collection('Routes')->document($tripData['route_id'])->snapshot();
        $stopIds = $routeRef->exists() ? ($routeRef->data()['stop_ids'] ?? []) : [];
        foreach ($stopIds as $sid) {
            $sSnap = $db->collection('Stops')->document($sid)->snapshot();
            $sName = $sSnap->exists() ? ($sSnap->data()['name'] ?? $sSnap->data()['stop_name'] ?? $sid) : $sid;
            $stops[] = [
                'name' => $sName,
                'time' => ''
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'booked_count' => $bookedCount,
        'capacity' => $capacity,
        'stops' => $stops
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System error']);
}
