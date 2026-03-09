<?php
session_start();
require_once '../../config.php';

// Admin-only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// Ensure IDs exist
$ids = $_POST['ids'] ?? [];

if (!is_array($ids) || empty($ids)) {
    header('Location: schedules_management.php?err=select_none');
    exit();
}

try {
    // Delete each selected schedule
    foreach ($ids as $id) {
        if (!empty($id)) {
            $firestore->database()
                ->collection('Schedules')
                ->document($id)
                ->delete();
        }
    }
    header('Location: schedules_management.php?msg=deleted');
    exit();
} catch (Exception $e) {
    header('Location: schedules_management.php?err=failed');
    exit();
}
?>