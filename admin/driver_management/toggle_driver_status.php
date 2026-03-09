<?php
session_start();
require_once '../../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = $_POST['id'] ?? null;
$targetStatus = $_POST['status'] ?? null; // This receives the NEW desired status

if (!$id || !$targetStatus) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

// 1. Validate status to prevent errors
$validStatuses = ['active', 'inactive'];
if (!in_array($targetStatus, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

// 2. Save the status directly (No flipping)
$firestore->database()
    ->collection('Staffs')
    ->document($id)
    ->update([
        ['path' => 'status', 'value' => $targetStatus],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ]);

echo json_encode([
    'success' => true,
    'new_status' => $targetStatus
]);
exit();
?>