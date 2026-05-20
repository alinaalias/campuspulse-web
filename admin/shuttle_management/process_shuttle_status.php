<?php
session_start();
require_once '../../config.php';
use Google\Cloud\Firestore\FieldValue; // Import this for server timestamps

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shuttleId = $_POST['shuttle_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!empty($shuttleId) && in_array($action, ['active', 'maintenance'])) {

        // Use ServerTimestamp so the JavaScript .toMillis() function works!
        $updateData = [
            'status' => $action,
            'last_updated' => FieldValue::serverTimestamp(),
            'updated_at' => date('Y-m-d H:i:s') // Keep your string for easy PHP reading
        ];

        // Cascading safety block
        if ($action === 'maintenance') {
            $updateData['is_online'] = false;
            $updateData['job_status'] = 'idle';
        }

        try {
            $firestore->database()->collection('Shuttles')->document($shuttleId)->set($updateData, ['merge' => true]);

            // Redirect with specific success message
            header('Location: shuttles_management.php?msg=updated');
            exit();
        } catch (Exception $e) {
            header('Location: shuttles_management.php?err=failed');
            exit();
        }
    }
}

header('Location: shuttles_management.php?err=failed');
exit();