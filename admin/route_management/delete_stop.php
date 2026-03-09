<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$stopId = $_GET['id'] ?? '';

if (!$stopId) {
    header('Location: routes_management.php?err=missing_id');
    exit();
}

try {
    $firestore->database()
        ->collection('Stops')
        ->document($stopId)
        ->delete();

    header('Location: routes_management.php?msg=stop_deleted#section-stops');
    exit();

} catch (Exception $e) {
    header('Location: routes_management.php?err=failed');
    exit();
}
?>