<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$routeId = $_GET['id'] ?? '';

if (!$routeId) {
    header('Location: routes_management.php?err=missing_id');
    exit();
}

try {
    $firestore
        ->collection('Routes')
        ->document($routeId)
        ->delete();

    header('Location: routes_management.php?msg=route_deleted');
    exit();

} catch (Exception $e) {
    // Log error if needed: error_log($e->getMessage());
    header('Location: routes_management.php?err=failed');
    exit();
}
?>