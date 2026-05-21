<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

header('Content-Type: application/json');

// 1. Basic Request Validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid Request Method']);
    exit();
}

// FIX: Use trim() to remove any invisible spaces from the scanner
$bookingId = trim($_POST['booking_id'] ?? '');
$scheduleId = trim($_POST['schedule_id'] ?? '');
$currentStopId = trim($_POST['current_stop_id'] ?? ''); // NEW: Capture the shuttle's current stop

if (!$bookingId || !$scheduleId) {
    echo json_encode(['success' => false, 'message' => 'Scan failed: Missing Ticket ID or Trip ID']);
    exit();
}

try {
    // 2. Fetch the Booking Document
    $bookingRef = $firestore->collection('Bookings')->document($bookingId);
    $bookingSnap = $bookingRef->snapshot();

    if (!$bookingSnap->exists()) {
        echo json_encode(['success' => false, 'message' => "Ticket invalid or not found. (Scanned: $bookingId)"]);
        exit();
    }

    $booking = $bookingSnap->data();

    // 3. Logic Validations

    // A. Check if the Ticket matches THIS specific Trip
    if (($booking['schedule_id'] ?? '') !== $scheduleId) {
        echo json_encode(['success' => false, 'message' => 'WRONG SHUTTLE! This ticket is for a different trip.']);
        exit();
    }

    // C. Check if cancelled (Moved up to prevent unnecessary processing)
    if (($booking['status'] ?? '') === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'This ticket was cancelled by the student.']);
        exit();
    }

    // B. Check if already used OR onboard
    $ticketStatus = $booking['ticket_status'] ?? '';
    $currentStatus = $booking['status'] ?? '';

    if ($currentStatus === 'onboard') {
        // --- CHECK-OUT ACTION ---

        // STRICT ENFORCEMENT: Ensure they are getting off at their assigned dropoff
        if (!empty($currentStopId) && !empty($booking['dropoff_stop_id']) && $currentStopId !== $booking['dropoff_stop_id']) {
            echo json_encode(['success' => false, 'message' => 'Invalid Stop: Please alight at your designated drop-off location.']);
            exit();
        }

        $bookingRef->set([
            'status' => 'completed',
            'check_out_time' => date('Y-m-d H:i:s')
        ], ['merge' => true]);

        // Update Schedule Count (Decrement)
        $schedRef = $firestore->collection('Schedules')->document($scheduleId);
        $schedSnap = $schedRef->snapshot();
        $newOnboardCount = 0;
        if ($schedSnap->exists()) {
            $schedData = $schedSnap->data();
            $currentOnboard = $schedData['onboard_count'] ?? 1;
            $newOnboardCount = max(0, $currentOnboard - 1);
            $schedRef->set(['onboard_count' => $newOnboardCount], ['merge' => true]);
        }

        // Get Student Name
        $studentName = "Student";
        $studentId = $booking['user_id'] ?? ($booking['student_id'] ?? '');
        if (!empty($studentId)) {
            $stSnap = $firestore->collection('Students')->document($studentId)->snapshot();
            if ($stSnap->exists()) {
                $stData = $stSnap->data();
                $studentName = $stData['full_name'] ?? ($stData['name'] ?? "Student");
            }
        }

        echo json_encode([
            'success' => true,
            'is_checkout' => true,
            'student_name' => $studentName,
            'new_count' => $newOnboardCount
        ]);
        exit();

    } elseif ($ticketStatus === 'used' || $currentStatus === 'completed') {
        echo json_encode(['success' => false, 'message' => 'Ticket already completed or used.']);
        exit();
    }

    // --- CHECK-IN ACTION ---

    // STRICT ENFORCEMENT: Ensure they board at their assigned pickup
    if (!empty($currentStopId) && !empty($booking['pickup_stop_id']) && $currentStopId !== $booking['pickup_stop_id']) {
        echo json_encode(['success' => false, 'message' => 'Invalid Stop: Please board at your designated pickup location.']);
        exit();
    }

    // 4. PERFORM CHECK-IN UPDATES
    $bookingRef->set([
        'ticket_status' => 'used',
        'status' => 'onboard',
        'check_in_time' => date('Y-m-d H:i:s')
    ], ['merge' => true]);

    // Update Schedule Count
    $schedRef = $firestore->collection('Schedules')->document($scheduleId);
    $schedSnap = $schedRef->snapshot();

    $newOnboardCount = 1;
    if ($schedSnap->exists()) {
        $schedData = $schedSnap->data();
        $currentOnboard = $schedData['onboard_count'] ?? 0;
        $newOnboardCount = $currentOnboard + 1;
        $schedRef->set(['onboard_count' => $newOnboardCount], ['merge' => true]);
    }

    // =========================================================
    // 5. GET STUDENT NAME (UPDATED LOGIC)
    // =========================================================
    $studentName = "Student";

    // Check both potential field names: 'user_id' OR 'student_id'
    $studentId = $booking['user_id'] ?? ($booking['student_id'] ?? '');

    if (!empty($studentId)) {
        $stSnap = $firestore->collection('Students')->document($studentId)->snapshot();
        if ($stSnap->exists()) {
            $stData = $stSnap->data();
            // Try 'full_name', then 'name', then fallback to "Student"
            $studentName = $stData['full_name'] ?? ($stData['name'] ?? "Student");
        }
    }

    echo json_encode([
        'success' => true,
        'student_name' => $studentName, // Will now contain the real name
        'new_count' => $newOnboardCount
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System Error: ' . $e->getMessage()]);
}
?>