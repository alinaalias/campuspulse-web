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
$reason = $_POST['reason'] ?? 'No reason provided';

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

$docRef = $firestore->collection('Staffs')->document($id);

// --- NEW: BACKEND COMPLIANCE LOCK ---
if ($targetStatus === 'active') {
    $doc = $docRef->snapshot();
    if (!$doc->exists()) {
        echo json_encode(['success' => false, 'message' => 'Driver not found.']);
        exit();
    }
    
    $data = $doc->data();
    $licExp = $data['license_expiry'] ?? '';
    $psvExp = $data['psv_expiry'] ?? '';
    
    $today = new DateTime('today');
    $isExpired = false;
    
    if (!empty($licExp)) {
        $licObj = new DateTime($licExp);
        if ($licObj < $today) $isExpired = true;
    } else {
        $isExpired = true; // null/empty means not valid
    }
    
    if (!empty($psvExp)) {
        $psvObj = new DateTime($psvExp);
        if ($psvObj < $today) $isExpired = true;
    } else {
        $isExpired = true;
    }
    
    if ($isExpired) {
        echo json_encode(['success' => false, 'message' => 'Cannot activate: License is expired.']);
        exit();
    }
}

// 2. Save the status directly with Tracking Data
$adminName = $_SESSION['full_name'] ?? 'Admin';

$docRef->update([
    ['path' => 'status', 'value' => $targetStatus],
    ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')],
    ['path' => 'last_status_change_reason', 'value' => $reason],
    ['path' => 'last_status_change_at', 'value' => date('Y-m-d H:i:s')],
    ['path' => 'last_status_change_admin', 'value' => $adminName]
]);

echo json_encode([
    'success' => true,
    'new_status' => $targetStatus
]);
exit();
?>