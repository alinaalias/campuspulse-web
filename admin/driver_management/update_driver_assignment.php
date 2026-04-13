<?php
session_start();
require_once '../../config.php';

header('Content-Type: application/json');

// 1. Check if user is Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// 2. Get the data
$driverId  = $_POST['driver_id'] ?? '';
$shuttleId = $_POST['shuttle_id'] ?? '';

// 3. Check if Driver ID is missing
if (!$driverId) {
    echo json_encode(['success' => false, 'message' => 'Error: Driver ID is missing']);
    exit();
}

// 4. Prepare the value (Convert empty string to NULL)
$finalValue = ($shuttleId === '' || $shuttleId === 'null') ? null : $shuttleId;

try {
    // 5. Pre-flight Check: Is this shuttle already taken?
    if ($finalValue !== null) {
        $existingStaffs = $firestore->database()->collection('Staffs')
            ->where('assigned_shuttle_id', '=', $finalValue)
            ->documents();
            
        foreach ($existingStaffs as $s) {
            if ($s->id() !== $driverId) {
                echo json_encode(['success' => false, 'message' => "Shuttle {$finalValue} is already assigned to driver {$s['full_name']}! Unassign them first."]);
                exit();
            }
        }
    }

    $ref = $firestore->database()->collection('Staffs')->document($driverId);

    // 6. Update the Database
    $ref->update([
        ['path' => 'assigned_shuttle_id', 'value' => $finalValue],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ]);

    echo json_encode(['success' => true, 'message' => 'Saved successfully!']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}