<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized Access");
}

$today = date('Y-m-d');

try {
    // Find all schedules BEFORE today that are still hanging as published/active
    $pastSchedules = $firestore->collection('Schedules')
        ->where('date', '<', $today)
        ->where('status', 'in', ['published', 'active']) 
        ->documents();

    $count = 0;
    $batch = $firestore->bulkWriter();
    $batchCount = 0;

    foreach ($pastSchedules as $doc) {
        // Change hanging schedules to missed to preserve history
        $batch->update($doc->reference(), [
            ['path' => 'status', 'value' => 'missed']
        ]);
        
        $count++;
        $batchCount++;

        if ($batchCount >= 400) {
            $batch->flush();
            $batch = $firestore->bulkWriter();
            $batchCount = 0;
        }
    }

    if ($batchCount > 0) {
        $batch->flush();
    }

    $msg = "Success! Swept $count dangling past schedules. History is safe.";
    header("Location: schedules_management.php?msg=" . urlencode($msg));
    exit();

} catch (Exception $e) {
    $error = "Archive failed: " . $e->getMessage();
    header("Location: schedules_management.php?err=" . urlencode($error));
    exit();
}
?>