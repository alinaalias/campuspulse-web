<?php
session_start();
// FIX: Force the server to use Malaysian time for all date() calculations
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

// 1. Security
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 2. Fetch Future Schedules
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$currentTime = date('H:i');
$currentTimestamp = time();

$query = $firestore->database()->collection('Schedules')
    ->where('driver_id', '=', $driverId)
    ->where('date', '>=', $today)
    ->orderBy('date', 'ASC')
    ->orderBy('departure_time', 'ASC');

$documents = $query->documents();
$groupedSchedules = [];

foreach ($documents as $doc) {
    if (!$doc->exists())
        continue;
    $data = $doc->data();
    $data['id'] = $doc->id();
    $data['status'] = $data['status'] ?? 'scheduled';

    if (in_array($data['status'], ['completed', 'cancelled', 'missed'])) {
        continue;
    }

    // STRICT 15-MINUTE BUFFER (900 seconds)
    // If the trip is older than 15 mins past departure, skip it here (it belongs in History)
    $jobTimestamp = strtotime($data['date'] . ' ' . $data['departure_time']);
    $isPastBuffer = $jobTimestamp < ($currentTimestamp - 900);

    if ($data['status'] !== 'active' && $isPastBuffer) {
        continue;
    }

    // Fetch Route
    $rSnap = $firestore->database()->collection('Routes')->document($data['route_id'])->snapshot();
    $data['route_name'] = $rSnap->exists() ? ($rSnap->data()['route_name'] ?? $data['route_id']) : 'Unknown Route';

    $etas = $data['etas'] ?? [];
    $stops = [];

    if (!empty($etas)) {
        $data['total_stops'] = count($etas);
    } elseif ($rSnap->exists()) {
        $stops = $rSnap->data()['stop_ids'] ?? [];
        $data['total_stops'] = count($stops);
    }

    $destId = $data['end_stop_id'] ?? '';
    if (empty($destId) && !empty($stops))
        $destId = end($stops);
    if (empty($destId) && !empty($etas))
        $destId = array_key_last($etas);

    if (!empty($destId)) {
        $sSnap = $firestore->database()->collection('Stops')->document($destId)->snapshot();
        $baseName = $sSnap->exists() ? ($sSnap->data()['stop_name'] ?? $sSnap->data()['name'] ?? 'Destination') : 'Destination';
        $destTime = $etas[$destId] ?? '';
        $data['dest_name'] = $baseName . ($destTime ? " ($destTime)" : "");
    } else {
        $data['dest_name'] = "Destination";
    }

    $data['is_ongoing'] = ($data['status'] === 'active');
    // Overdue triggers as soon as the clock passes departure_time, up until the 15 min buffer kicks it out
    $data['is_overdue'] = ($data['date'] === $today && $data['departure_time'] < $currentTime && $data['status'] !== 'active');

    $dateKey = $data['date'];
    if (!isset($groupedSchedules[$dateKey]))
        $groupedSchedules[$dateKey] = [];
    $groupedSchedules[$dateKey][] = $data;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Schedule</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css?v=<?= time() ?>">
</head>

<body class="driver-body">

    <div class="driver-header">
        <div style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <a href="driver_dashboard.php" style="color: white; font-size: 1.2rem;">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h2 style="margin: 0; font-size: 1.6rem; font-weight: 700; line-height: 1;">My Schedule</h2>
                </div>
            </div>
            <a href="driver_trip_history.php"
                style="color: white; font-size: 0.85rem; background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; font-weight: 600; text-decoration: none; border: 1px solid rgba(255,255,255,0.1);">
                <i class="fas fa-history" style="margin-right:5px;"></i> History
            </a>
        </div>
    </div>

    <div class="driver-container">

        <?php if (empty($groupedSchedules)): ?>
            <div class="app-card"
                style="text-align: center; padding: 50px 20px; background: white; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <i class="far fa-calendar-times" style="font-size: 3.5rem; color: #e2e8f0; margin-bottom: 15px;"></i>
                <h3 style="margin: 0 0 5px; color: #2d3748; font-weight: 700;">No Upcoming Trips</h3>
                <p style="color: #718096; font-size: 0.95rem; margin: 0;">Your schedule is totally clear!</p>
            </div>
        <?php else: ?>

            <?php foreach ($groupedSchedules as $date => $trips): ?>
                <?php
                $displayDate = date('l, j M', strtotime($date));
                if ($date === $today)
                    $displayDate = "TODAY";
                elseif ($date === $tomorrow)
                    $displayDate = "TOMORROW";
                ?>

                <div class="date-header"><?= $displayDate ?></div>

                <?php foreach ($trips as $trip): ?>
                    <div class="sched-ticket <?= $trip['is_ongoing'] ? 'active-trip' : '' ?>">

                        <div class="sched-top">
                            <div class="sched-time-block">
                                <div class="time-big"><?= date('H:i', strtotime($trip['departure_time'])) ?></div>
                                <div class="time-small"><?= date('A', strtotime($trip['departure_time'])) ?></div>
                            </div>

                            <div class="sched-details">
                                <?php if ($trip['is_overdue']): ?>
                                    <div
                                        style="color:#e53e3e; font-size:0.7rem; font-weight:700; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                        <i class="fas fa-exclamation-circle"></i> LATE DEPARTURE
                                    </div>
                                <?php endif; ?>
                                <?php if ($trip['is_ongoing']): ?>
                                    <div
                                        style="color:#27ae60; font-size:0.7rem; font-weight:700; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                        <span
                                            style="width:6px; height:6px; background:#27ae60; border-radius:50%; animation: pulse 2s infinite;"></span>
                                        IN PROGRESS
                                    </div>
                                <?php endif; ?>

                                <h4 class="route-title"><?= htmlspecialchars($trip['route_name']) ?></h4>
                                <p class="route-dest">
                                    <i class="fas fa-flag-checkered"></i> <?= htmlspecialchars($trip['dest_name']) ?>
                                </p>
                            </div>
                        </div>

                        <div class="action-area">
                            <div class="badge-group">
                                <div class="stat-badge">
                                    <i class="fas fa-users"></i> <?= ($trip['booked_count'] ?? 0) ?> /
                                    <?= ($trip['capacity'] ?? 13) ?>
                                </div>
                                <div class="stat-badge">
                                    <i class="fas fa-map-signs"></i> <?= ($trip['total_stops'] ?? 0) ?> stops
                                </div>
                            </div>

                            <?php if ($trip['is_ongoing']): ?>
                                <a href="active_trip.php?id=SCHED:<?= $trip['id'] ?>" class="btn-pill green">
                                    RESUME <i class="fas fa-play"></i>
                                </a>
                            <?php else: ?>
                                <button
                                    onclick="previewTrip('SCHED:<?= $trip['id'] ?>', '<?= htmlspecialchars(addslashes($trip['route_name'])) ?>', '<?= date('H:i', strtotime($trip['departure_time'])) ?>', '<?= $trip['date'] ?>', '<?= $today ?>', <?= $trip['is_ongoing'] ? 'true' : 'false' ?>)"
                                    class="btn-pill dark">
                                    DETAILS <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <?php include 'preview_modal.php'; ?>
    <?php include 'driver_navbar.php'; ?>

</body>

</html>