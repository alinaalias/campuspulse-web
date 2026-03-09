<?php
require_once '../../config.php';

$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: students_management.php?msg=error');
    exit();
}

$studentRef = $firestore->database()->collection('Students')->document($id);
$snapshot = $studentRef->snapshot();

if (!$snapshot->exists()) {
    header('Location: students_management.php?msg=notfound');
    exit();
}

$student = $snapshot->data();

if (isset($student['status']) && $student['status'] === 'inactive') {
    $studentRef->delete();
    header('Location: students_management.php?msg=deleted');
    exit();
} else {
    header('Location: students_management.php?msg=active');
    exit();
}
