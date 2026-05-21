<?php
session_start();
require_once '../../config.php';

// Check Admin Access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = $_POST['id'] ?? '';
$targetStatus = $_POST['status'] ?? ''; // This receives the NEW desired status
$reason = $_POST['reason'] ?? 'No reason provided';

if (!$id || !$targetStatus) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

// Integrity Check: Graduated students MUST be inactive
if ($reason === 'Graduated') {
    $targetStatus = 'inactive';
}

// Ensure status is valid
$validStatuses = ['active', 'inactive'];
if (!in_array($targetStatus, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

$adminName = $_SESSION['full_name'] ?? 'Admin';

try {
    $firestore
        ->collection('Students')
        ->document($id)
        ->update([
            ['path' => 'status', 'value' => $targetStatus],
            ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')],
            ['path' => 'status_update_reason', 'value' => $reason],
            ['path' => 'status_update_at', 'value' => date('c')],
            ['path' => 'status_update_admin', 'value' => $adminName]
        ]);

    echo json_encode(['success' => true, 'new_status' => $targetStatus]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>