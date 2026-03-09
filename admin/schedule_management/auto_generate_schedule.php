<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$date     = $_POST['date'] ?? '';
$routeId  = $_POST['route_id'] ?? '';
$shuttles = $_POST['shuttles'] ?? [];
$interval = intval($_POST['interval'] ?? 15);
$peaks    = $_POST['peaks'] ?? [];

if (!$date || !$routeId || empty($shuttles) || empty($peaks)) {
    echo json_encode(['success' => false, 'message' => 'Missing required input']);
    exit();
}

// Re-index shuttles array to ensure keys are 0, 1, 2... for easy rotation
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

/* =========================
   Peak windows
========================= */
$windowMap = [
    'morning' => ['06:30', '09:00'],
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

    if (!isset($windowMap[$peakKey])) continue;

    [$start, $end] = $windowMap[$peakKey];
    $time = strtotime("$date $start");
    $endT = strtotime("$date $end");

    while ($time < $endT) {

        // 1. SELECT ONE SHUTTLE FOR THIS TIME SLOT (Round Robin)
        $shuttleId = $shuttles[$rotationIndex % $shuttleCount];
        
        // 2. INCREMENT COUNTER IMMEDIATELY (So next loop gets next shuttle)
        $rotationIndex++;

        /* Shuttle capacity check */
        $shuttleSnap = $firestore->database()->collection('Shuttles')->document($shuttleId)->snapshot();
        if (!$shuttleSnap->exists()) {
            $time += $interval * 60; // Skip time slot if shuttle invalid
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
        foreach ($driversSnap as $d) { $driverIds[] = $d->id(); }

        if (empty($driverIds)) {
             // If no driver, skip this slot but move time forward
             $time += $interval * 60;
             continue;
        }

        /* Try to assign a driver */
        foreach ($driverIds as $driverId) {

            /* DRIVER CLASH CHECK */
            $clash = $firestore->database()->collection('Schedules')
                ->where('date', '=', $date)
                ->where('departure_time', '=', date('H:i', $time))
                ->where('driver_id', '=', $driverId)
                ->documents();

            if (!$clash->isEmpty()) {
                continue; // Driver busy, try next driver for this shuttle
            }

            // SUCCESS: Create Schedule
            $scheduleId = generateCustomId('schedules', 'SCHED', $firestore);

            $firestore->database()->collection('Schedules')->document($scheduleId)->set([
                'schedule_id'    => $scheduleId,
                'date'           => $date,
                'route_id'       => $routeId,
                'start_stop_id'  => $route['start_stop_id'],
                'end_stop_id'    => $route['end_stop_id'],
                'departure_time' => date('H:i', $time),
                'shuttle_id'     => $shuttleId,
                'driver_id'      => $driverId,
                'peak'           => $peakKey,
                'capacity'       => $capacity,
                'booked_count'   => 0,
                'status'         => 'published',
                'created_at'     => date('Y-m-d H:i:s')
            ]);

            $totalCreated++;
            break; // Found a driver, stop checking drivers for this slot
        }

        // Move to next time slot
        $time += $interval * 60;
    }
}

if ($totalCreated === 0) {
    echo json_encode(['success' => false, 'message' => 'No schedules generated (check drivers/shuttles)']);
    exit();
}

echo json_encode(['success' => true, 'count' => $totalCreated]);
?>