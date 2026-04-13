<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? '';

if ($id) {
    try {
        // CASCADING SAFETY: Deactivating means the bus is permanently off the road.
        $firestore->database()
            ->collection('Shuttles')
            ->document($id)
            ->update([
                ['path' => 'status', 'value' => 'inactive'],
                ['path' => 'is_online', 'value' => false],
                ['path' => 'job_status', 'value' => 'Idle'],
                ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
            ]);

        header('Location: shuttles_management.php?msg=inactive');
        exit();
    } catch (Exception $e) {
        header('Location: shuttles_management.php?err=failed');
        exit();
    }
} else {
    header('Location: shuttles_management.php');
    exit();
}