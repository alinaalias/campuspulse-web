<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? '';

if (!$id) {
    header('Location: routes_management.php?err=missing_id');
    exit();
}

try {
    // PERMANENT DELETE: Wipes the document from Firestore completely
    $firestore
        ->collection('Zones')
        ->document($id)
        ->delete();

    header('Location: routes_management.php?msg=zone_deleted#section-zones');
    exit();

} catch (Exception $e) {
    header('Location: routes_management.php?err=failed');
    exit();
}
?>