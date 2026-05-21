<?php
session_start();
require_once '../../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$date = $_POST['date'] ?? '';
$timeStr = $_POST['time'] ?? '';
$routeId = $_POST['route_id'] ?? '';
$direction = $_POST['direction'] ?? '';
$shuttleId = $_POST['shuttle_id'] ?? '';

if (!$date || !$timeStr || !$routeId || !$direction || !$shuttleId) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    $db = $firestore;

    // 1. Fetch Shuttle
    $shuttleSnap = $db->collection('Shuttles')->document($shuttleId)->snapshot();
    if (!$shuttleSnap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Shuttle not found']);
        exit();
    }
    $capacity = intval($shuttleSnap->data()['capacity'] ?? 0);

    // Fetch Driver for shuttle
    $driversSnap = $db->collection('Staffs')
        ->where('role', '=', 'driver')
        ->where('assigned_shuttle_id', '=', $shuttleId)
        ->where('status', '=', 'active')
        ->documents();

    $driverId = null;
    foreach ($driversSnap as $d) {
        $driverId = $d->id();
        break;
    }
    if (!$driverId) {
        echo json_encode(['success' => false, 'message' => 'No active driver assigned to this shuttle.']);
        exit();
    }

    // 2. Fetch Route
    $routeSnap = $db->collection('Routes')->document($routeId)->snapshot();
    if (!$routeSnap->exists()) {
        echo json_encode(['success' => false, 'message' => 'Route not found.']);
        exit();
    }
    $route = $routeSnap->data();
    $stopsArr = $route['stop_ids'] ?? [];

    // Calculate ETAs
    $baseTime = strtotime("$date $timeStr");
    $etas = [];
    foreach ($stopsArr as $stopObj) {
        if (is_array($stopObj) && isset($stopObj['stop_id'], $stopObj['offset'])) {
            $etas[$stopObj['stop_id']] = date('H:i', $baseTime + ($stopObj['offset'] * 60));
        } else {
            $etas[is_string($stopObj) ? $stopObj : 'unknown'] = date('H:i', $baseTime);
        }
    }

    // Calculate block times for clash check
    $lastOffset = 0;
    if (!empty($stopsArr)) {
        $lastStop = end($stopsArr);
        if (is_array($lastStop) && isset($lastStop['offset'])) {
            $lastOffset = intval($lastStop['offset']);
        }
    }
    $totalBlockedMinutes = ($lastOffset * 2) + 10;
    $newStartTime = $baseTime;
    $newEndTime = $baseTime + ($totalBlockedMinutes * 60);

    // 3. Clash Check
    $driverSchedules = $db->collection('Schedules')
        ->where('date', '=', $date)
        ->where('driver_id', '=', $driverId)
        ->documents();

    $hasClash = false;
    $clashDetails = '';

    foreach ($driverSchedules as $sched) {
        $sData = $sched->data();

        // FIX: The Status Bypass
        // If the schedule is already done or cancelled, do not calculate a clash for it!
        $currentStatus = $sData['status'] ?? '';
        if ($currentStatus === 'completed' || $currentStatus === 'cancelled' || $currentStatus === 'missed') {
            continue;
        }

        $sDep = strtotime($date . ' ' . $sData['departure_time']);

        $sDurationMins = $lastOffset * 2;
        if (!empty($sData['etas']) && is_array($sData['etas'])) {
            $etasVals = array_values($sData['etas']);
            $sEndTimeStr = end($etasVals);
            $sEnd = strtotime($date . ' ' . $sEndTimeStr);
            $sDurationMins = ($sEnd - $sDep) / 60;
        }
        $sEndBlocked = $sDep + ($sDurationMins * 60) + (10 * 60);

        // Overlap condition
        if ($newStartTime < $sEndBlocked && $newEndTime > $sDep) {
            $hasClash = true;
            $clashDetails = "Driver has schedule " . $sData['schedule_id'] . " from " . date('H:i', $sDep) . " to " . date('H:i', $sEndBlocked);
            break;
        }
    }

    if ($hasClash) {
        echo json_encode(['success' => false, 'message' => "Schedule conflict! " . $clashDetails]);
        exit();
    }

    // 4. Determine Peak
    $hour = intval(date('H', $baseTime));
    $peak = 'none';
    if ($hour >= 7 && $hour <= 10)
        $peak = 'morning';
    else if ($hour >= 16 && $hour <= 20)
        $peak = 'evening';

    // 5. Generate and Insert
    $scheduleId = generateCustomId('schedules', 'SCHED', $firestore);

    $db->collection('Schedules')->document($scheduleId)->set([
        'schedule_id' => $scheduleId,
        'date' => $date,
        'direction' => $direction,
        'route_id' => $routeId,
        'start_stop_id' => $route['start_stop_id'],
        'end_stop_id' => $route['end_stop_id'],
        'departure_time' => date('H:i', $baseTime),
        'etas' => $etas,
        'shuttle_id' => $shuttleId,
        'driver_id' => $driverId,
        'peak' => $peak,
        'capacity' => $capacity,
        'booked_count' => 0,
        'status' => 'published',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
?>