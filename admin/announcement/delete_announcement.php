<?php
session_start();
require_once '../../config.php';

$id = $_GET['id'];
if ($id) {
    $firestore->database()->collection('Announcements')->document($id)->delete();
}
header("Location: announcements_management.php");
exit;
