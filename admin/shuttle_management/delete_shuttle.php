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
        $firestore->database()
            ->collection('Shuttles')
            ->document($id)
            ->update([
                ['path' => 'status', 'value' => 'inactive']
            ]);
        
        header('Location: shuttles_management.php?msg=inactive');
        exit();
    } catch (Exception $e) {
        // Optional: handle error via query param
        header('Location: shuttles_management.php?err=failed');
        exit();
    }
} else {
    header('Location: shuttles_management.php');
    exit();
}
?>