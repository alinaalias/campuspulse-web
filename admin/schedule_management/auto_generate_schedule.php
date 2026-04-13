<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$date = $_POST['date'] ?? '';
$routeId = $_POST['route_id'] ?? '';
$shuttles = $_POST['shuttles'] ?? [];
$interval = intval($_POST['interval'] ?? 15);
$peaks = $_POST['peaks'] ?? [];

// Restrict to fixed intervals for safety
$allowedIntervals = [10, 15, 20, 30];
if (!in_array($interval, $allowedIntervals)) {
    echo json_encode(['success' => false, 'message' => 'Invalid interval selected']);
    exit();
}

if (!$date || !$routeId || empty($shuttles) || empty($peaks)) {
    echo json_encode(['success' => false, 'message' => 'Missing required input']);
    exit();
}

// Re-index shuttles array
$shuttles = array_values($shuttles);
$shuttleCount = count($shuttles);

/* =========================
   Fetch route
========================= */
$routeSnap = $firestore->database()->collection('Routes')->document($routeId)->snapshot();
if (!$routeSnap->exists()) {
    echo json_encode(['success' => false, 'message' => 'Route not found']);
    exit();
}
$route = $routeSnap->data();

/* ====================================================================
   PRE-FLIGHT MATH CHECK (Do we have enough shuttles for this interval?)
   ==================================================================== */
$stopsArr = $route['stop_ids'] ?? [];
$lastOffset = 0;
if (!empty($stopsArr)) {
    $lastStop = end($stopsArr);
    if (is_array($lastStop) && isset($lastStop['offset'])) {
        $lastOffset = intval($lastStop['offset']);
    }
}

// Calculate the total time a shuttle is blocked per trip (Forward + Return + Break)
$totalBlockedMinutes = ($lastOffset * 2) + 10;

// Calculate how many shuttles are strictly required to maintain the interval
// E.g., 60 mins total block / 15 min interval = 4 shuttles needed.
$requiredShuttles = ceil($totalBlockedMinutes / $interval);

if ($shuttleCount < $requiredShuttles) {
    echo json_encode([
        'success' => false,
        'message' => "Insufficient Shuttles! This route requires at least $requiredShuttles shuttles to maintain a $interval-minute interval. You only selected $shuttleCount."
    ]);
    exit();
}

/* =========================
   Peak windows
========================= */
$windowMap = [
    'morning' => ['07:00', '10:00'],
    'evening' => ['17:00', '19:30']
];

/* =========================
   DUPLICATE CHECK
========================= */
foreach ($peaks as $peak) {
    $existing = $firestore->database()->collection('Schedules')
        ->where('date', '=', $date)
        ->where('route_id', '=', $routeId)
        ->where('peak', '=', $peak)
        ->documents();

    if (!$existing->isEmpty()) {
        echo json_encode(['success' => false, 'message' => "Schedules already exist for $peak peak."]);
        exit();
    }
}

$totalCreated = 0;
$rotationIndex = 0; // Global counter to rotate shuttles across time slots

/* =========================
   GENERATION LOOP
========================= */
foreach ($peaks as $peakKey) {

    if (!isset($windowMap[$peakKey]))
        continue;

    [$start, $end] = $windowMap[$peakKey];
    $time = strtotime("$date $start");
    $endT = strtotime("$date $end");

    while ($time < $endT) {

        // 1. SELECT ONE SHUTTLE FOR THIS TIME SLOT (Round Robin)
        $shuttleId = $shuttles[$rotationIndex % $shuttleCount];

        // 2. INCREMENT COUNTER IMMEDIATELY
        $rotationIndex++;

        /* Shuttle capacity check */
        $shuttleSnap = $firestore->database()->collection('Shuttles')->document($shuttleId)->snapshot();
        if (!$shuttleSnap->exists()) {
            $time += $interval * 60;
            continue;
        }

        $capacity = intval($shuttleSnap->data()['capacity'] ?? 0);
        if ($capacity <= 0) {
            $time += $interval * 60;
            continue;
        }

        /* Drivers for shuttle */
        $driversSnap = $firestore->database()->collection('Staffs')
            ->where('role', '=', 'driver')
            ->where('assigned_shuttle_id', '=', $shuttleId)
            ->where('status', '=', 'active')
            ->documents();

        $driverIds = [];
        foreach ($driversSnap as $d) {
            $driverIds[] = $d->id();
        }

        if (empty($driverIds)) {
            $time += $interval * 60;
            continue;
        }

        /* Try to assign a driver */
        foreach ($driverIds as $driverId) {

            /* Calculate ETAs */
            $etas = [];
            foreach ($stopsArr as $stopObj) {
                if (is_array($stopObj) && isset($stopObj['stop_id'], $stopObj['offset'])) {
                    $etas[$stopObj['stop_id']] = date('H:i', $time + ($stopObj['offset'] * 60));
                } else {
                    $etas[is_string($stopObj) ? $stopObj : 'unknown'] = date('H:i', $time);
                }
            }

            /* ROBUST DRIVER CLASH CHECK */
            $driverSchedules = $firestore->database()->collection('Schedules')
                ->where('date', '=', $date)
                ->where('driver_id', '=', $driverId)
                ->documents();

            $hasClash = false;
            $newStartTime = $time;
            $newEndTime = $time + ($totalBlockedMinutes * 60);

            foreach ($driverSchedules as $sched) {
                $sData = $sched->data();
                $sDep = strtotime($date . ' ' . $sData['departure_time']);

                if (!empty($sData['etas']) && is_array($sData['etas'])) {
                    $etasVals = array_values($sData['etas']);
                    $sEndTimeStr = end($etasVals);
                    $sEnd = strtotime($date . ' ' . $sEndTimeStr);
                    $sDurationMins = ($sEnd - $sDep) / 60;
                    $sEndBlocked = $sEnd + ($sDurationMins * 60) + (10 * 60);
                } else {
                    $sEndBlocked = $sDep + ($interval * 60 * 2) + (10 * 60);
                }

                if ($newStartTime < $sEndBlocked && $newEndTime > $sDep) {
                    $hasClash = true;
                    break;
                }
            }

            if ($hasClash) {
                continue; // Try next driver
            }

            // SUCCESS: Create Schedule
            $scheduleId = generateCustomId('schedules', 'SCHED', $firestore);

            $firestore->database()->collection('Schedules')->document($scheduleId)->set([
                'schedule_id' => $scheduleId,
                'date' => $date,
                'route_id' => $routeId,
                'start_stop_id' => $route['start_stop_id'],
                'end_stop_id' => $route['end_stop_id'],
                'departure_time' => date('H:i', $time),
                'etas' => $etas,
                'shuttle_id' => $shuttleId,
                'driver_id' => $driverId,
                'peak' => $peakKey,
                'capacity' => $capacity,
                'booked_count' => 0,
                'status' => 'published',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $totalCreated++;
            break;
        }

        $time += $interval * 60;
    }
}

if ($totalCreated === 0) {
    echo json_encode(['success' => false, 'message' => 'No schedules generated (check drivers/shuttles availability)']);
    exit();
}

echo json_encode(['success' => true, 'count' => $totalCreated]);
?>