<?php
// driver/verify_ticket.php
session_start();
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

if (!$bookingId || !$scheduleId) {
    echo json_encode(['success' => false, 'message' => 'Scan failed: Missing Ticket ID or Trip ID']);
    exit();
}

try {
    // 2. Fetch the Booking Document
    $bookingRef = $firestore->database()->collection('Bookings')->document($bookingId);
    $bookingSnap = $bookingRef->snapshot();

    if (!$bookingSnap->exists()) {
        // DEBUG: Return the ID we tried to find so you can see if it's wrong
        echo json_encode(['success' => false, 'message' => "Ticket invalid or not found. (Scanned: $bookingId)"]);
        exit();
    }

    $booking = $bookingSnap->data();

    // 3. Logic Validations
    
    // A. Check if the Ticket matches THIS specific Trip
    if (($booking['schedule_id'] ?? '') !== $scheduleId) {
        echo json_encode(['success' => false, 'message' => 'WRONG BUS! This ticket is for a different trip.']);
        exit();
    }

    // B. Check if already used
    if (($booking['ticket_status'] ?? '') === 'used' || ($booking['status'] ?? '') === 'onboard') {
        echo json_encode(['success' => false, 'message' => 'Ticket already scanned (Used).']);
        exit();
    }

    // C. Check if cancelled
    if (($booking['status'] ?? '') === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'This ticket was cancelled by the student.']);
        exit();
    }

    // 4. PERFORM UPDATES
    $bookingRef->set([
        'ticket_status' => 'used',       
        'status'        => 'onboard',    
        'check_in_time' => date('Y-m-d H:i:s')
    ], ['merge' => true]);

    // Update Schedule Count
    $schedRef = $firestore->database()->collection('Schedules')->document($scheduleId);
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
        $stSnap = $firestore->database()->collection('Students')->document($studentId)->snapshot();
        if ($stSnap->exists()) {
            $stData = $stSnap->data();
            // Try 'full_name', then 'name', then fallback to "Student"
            $studentName = $stData['full_name'] ?? ($stData['name'] ?? "Student");
        }
    }

    echo json_encode([
        'success'      => true, 
        'student_name' => $studentName, // Will now contain the real name
        'new_count'    => $newOnboardCount
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System Error: ' . $e->getMessage()]);
}
?>