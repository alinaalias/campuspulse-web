<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scheduleId = $_POST['schedule_id'] ?? '';
    // Capture reason from early termination (if applies)
    $terminationReason = trim($_POST['termination_reason'] ?? '');

    if (empty($scheduleId)) {
        header('Location: driver_dashboard.php?error=MissingSchedule');
        exit();
    }

    $db = $firestore->database();
    $schedRef = $db->collection('Schedules')->document($scheduleId);
    $schedSnap = $schedRef->snapshot();

    if ($schedSnap->exists()) {
        $data = $schedSnap->data();
        $shuttleId = $data['shuttle_id'] ?? '';

        // --- Action A: Finalize Schedule ---
        $updateFields = [
            ['path' => 'status', 'value' => 'completed'],
            ['path' => 'completed_at', 'value' => date('Y-m-d H:i:s')]
        ];

        if (!empty($terminationReason)) {
            $updateFields[] = ['path' => 'termination_reason', 'value' => $terminationReason];
        }

        try {
            $schedRef->update($updateFields);
        } catch (Exception $e) {
            header('Location: driver_dashboard.php?error=ScheduleUpdateFailed');
            exit();
        }

        // --- Action B: Reset Shuttle Job Status ---
        if (!empty($shuttleId)) {
            try {
                $db->collection('Shuttles')->document($shuttleId)->update([
                    ['path' => 'job_status', 'value' => 'Idle']
                ]);
            } catch (Exception $e) {
                // Failsafe catch
            }
        }

        // --- Action C: Batch Complete Passengers ---
        $passengerCount = 0;
        try {
            $onboardBookings = $db->collection('Bookings')
                ->where('schedule_id', '=', $scheduleId)
                ->where('status', '=', 'onboard')
                ->documents();

            foreach ($onboardBookings as $booking) {
                $booking->reference()->update([
                    ['path' => 'status', 'value' => 'completed']
                ]);
                $passengerCount++;
            }
        } catch (Exception $e) {
            // Log silently or ignore
        }

        // --- Redirect to Success State ---
        header("Location: driver_dashboard.php?msg=TripCompleted&count=" . $passengerCount);
        exit();
    }
}

header('Location: driver_dashboard.php?error=InvalidRequest');
exit();
