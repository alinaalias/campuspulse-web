<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    // secure graceful exit parameters
    try {
        $firestore->database()->collection('Staffs')->document($_SESSION['user_id'])->update([
            ['path' => 'duty_status', 'value' => 'offline'],
            ['path' => 'current_trip_id', 'value' => null]
        ]);
    } catch (Exception $e) {}
}

// Destroy all session data to log the admin out
session_unset();
session_destroy();

// Redirect to login page
header('Location: index.php');
exit();
?>
