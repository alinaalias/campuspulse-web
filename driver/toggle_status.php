<?php
session_start();
require_once '../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$driverId = $_SESSION['user_id'];
$db = $firestore->database();

try {
    $driverRef = $db->collection('Staffs')->document($driverId);
    $driverSnap = $driverRef->snapshot();

    if ($driverSnap->exists()) {
        $driverData = $driverSnap->data();
        $currentStatus = $driverData['duty_status'] ?? 'offline';
        $newStatus = ($currentStatus === 'online') ? 'offline' : 'online';
        $isOnlineBool = ($newStatus === 'online');
        $nowStr = date('Y-m-d H:i:s');

        // 1. Update Driver Duty Status
        $driverRef->update([
            ['path' => 'duty_status', 'value' => $newStatus],
            ['path' => 'last_updated', 'value' => $nowStr]
        ]);

        // 2. Synchronize Assigned Shuttle (Atomic Sync)
        $shuttleId = $driverData['assigned_shuttle_id'] ?? '';
        if (!empty($shuttleId)) {
            $db->collection('Shuttles')->document($shuttleId)->update([
                ['path' => 'is_online', 'value' => $isOnlineBool],
                ['path' => 'last_updated', 'value' => $nowStr]
            ]);
        }

        echo json_encode(['success' => true, 'new_status' => $newStatus, 'is_online' => $isOnlineBool]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>