<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawId = $_POST['schedule_id'] ?? '';
    // Capture reason from early termination (if applies)
    $terminationReason = trim($_POST['termination_reason'] ?? '');

    if (empty($rawId)) {
        header('Location: driver_dashboard.php?error=MissingData');
        exit();
    }

    $db = $firestore->database();
    $driverId = $_SESSION['user_id'];
    $passengerCount = 0;

    if (strpos($rawId, 'SCHED:') === 0) {
        // --- SCHEDULE FINISH LOGIC ---
        $scheduleId = substr($rawId, 6);
        $schedRef = $db->collection('Schedules')->document($scheduleId);
        $schedSnap = $schedRef->snapshot();

        if ($schedSnap->exists()) {
            $data = $schedSnap->data();
            $shuttleId = $data['shuttle_id'] ?? '';

            $updateFields = [
                ['path' => 'status', 'value' => 'completed'],
                ['path' => 'completed_at', 'value' => date('Y-m-d H:i:s')]
            ];

            if (!empty($terminationReason)) {
                $updateFields[] = ['path' => 'termination_reason', 'value' => $terminationReason];
            }

            try {
                $schedRef->update($updateFields);
            } catch (Exception $e) {}

            if (!empty($shuttleId)) {
                try {
                    $db->collection('Shuttles')->document($shuttleId)->update([
                        ['path' => 'job_status', 'value' => 'Idle']
                    ]);
                } catch (Exception $e) {}
            }

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
            } catch (Exception $e) {}
        }
    } elseif (strpos($rawId, 'BOOK:') === 0) {
        // --- ON-DEMAND FINISH LOGIC ---
        $bookingId = substr($rawId, 5);
        
        try {
            $db->collection('Bookings')->document($bookingId)->update([
                ['path' => 'status', 'value' => 'completed'],
                ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
            ]);
            
            // Check if we need to set the shuttle to IDLE
            $activeCheck = $db->collection('Bookings')
                ->where('driver_id', '=', $driverId)
                ->where('status', 'in', ['confirmed', 'arriving', 'onboard'])
                ->documents();

            if (empty($activeCheck)) {
                $staffSnap = $db->collection('Staffs')->document($driverId)->snapshot();
                $shuttleId = $staffSnap->data()['assigned_shuttle_id'] ?? null;
                if ($shuttleId) {
                    $db->collection('Shuttles')->document($shuttleId)->update([
                        ['path' => 'job_status', 'value' => 'Idle']
                    ]);
                }
            }
        } catch (Exception $e) {}
    }

    // --- CLEAR CURRENT TRIP ID ---
    try {
        $db->collection('Staffs')->document($driverId)->update([
            ['path' => 'current_trip_id', 'value' => '']
        ]);
    } catch (Exception $e) {}

    // --- Redirect to Success State ---
    $finalId = isset($scheduleId) ? $scheduleId : (isset($bookingId) ? $bookingId : '');
    $finalType = isset($scheduleId) ? 'schedule' : (isset($bookingId) ? 'ondemand' : '');
    header("Location: trip_history_detail.php?id=" . urlencode($finalId) . "&type=" . urlencode($finalType) . "&completed=true");
    exit();
}

header('Location: driver_dashboard.php?error=InvalidRequest');
exit();
