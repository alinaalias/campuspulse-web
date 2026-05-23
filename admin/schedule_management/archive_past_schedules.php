<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized Access");
}

$today = date('Y-m-d');

try {
    // Find all schedules BEFORE today that are NOT YET archived
    $pastSchedules = $firestore->collection('Schedules')
        ->where('date', '<', $today)
        ->where('status', '!=', 'archived') 
        ->documents();

    $count = 0;
    $batch = $firestore->bulkWriter();
    $batchCount = 0;

    foreach ($pastSchedules as $doc) {
        // CHANGED: Update status instead of delete
        $batch->update($doc->reference(), [
            ['path' => 'status', 'value' => 'archived']
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

    $msg = "Success! Archived $count past schedules. History is safe.";
    header("Location: schedules_management.php?msg=" . urlencode($msg));
    exit();

} catch (Exception $e) {
    $error = "Archive failed: " . $e->getMessage();
    header("Location: schedules_management.php?err=" . urlencode($error));
    exit();
}
?>