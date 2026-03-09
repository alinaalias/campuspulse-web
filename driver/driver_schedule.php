<?php
session_start();
require_once '../config.php';

// 1. Security
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 2. Fetch Future Schedules
$today = date('Y-m-d');
$currentTime = date('H:i');

$query = $firestore->database()->collection('Schedules')
    ->where('driver_id', '=', $driverId)
    ->where('date', '>=', $today)
    ->orderBy('date', 'ASC')
    ->orderBy('departure_time', 'ASC');

$documents = $query->documents();
$groupedSchedules = [];

foreach ($documents as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $data['id'] = $doc->id();
    
    if ($data['date'] === $today && $data['departure_time'] < $currentTime) continue;

    // Fetch Route
    $rSnap = $firestore->database()->collection('Routes')->document($data['route_id'])->snapshot();
    $data['route_name'] = $rSnap->exists() ? ($rSnap->data()['route_name'] ?? $data['route_id']) : 'Unknown Route';
    
    // Fetch Stop details for display
    $stopsSummary = "Standard Route";
    if ($rSnap->exists()) {
        $stops = $rSnap->data()['stop_ids'] ?? [];
        $data['total_stops'] = count($stops);
    }

    $destId = $data['end_stop_id'] ?? '';
    if (empty($destId) && !empty($stops)) $destId = end($stops);
    
    if (!empty($destId)) {
        $sSnap = $firestore->database()->collection('Stops')->document($destId)->snapshot();
        $data['dest_name'] = $sSnap->exists() ? ($sSnap->data()['stop_name'] ?? 'Destination') : 'Destination';
    } else {
        $data['dest_name'] = "Destination";
    }

    $dateKey = $data['date'];
    if (!isset($groupedSchedules[$dateKey])) $groupedSchedules[$dateKey] = [];
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
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="driver-body">

    <div class="driver-header" style="height: 140px; align-items: flex-start; padding-top: 30px;">
        <div style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <a href="driver_dashboard.php" style="color: white; font-size: 1.2rem;"><i class="fas fa-arrow-left"></i></a>
                <h2 style="margin: 0; font-size: 1.4rem; font-weight: 600;">My Schedule</h2>
            </div>
            <a href="history.php" style="color: rgba(255,255,255,0.8); font-size: 0.9rem;">History</a>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -60px;">
        
        <?php if (empty($groupedSchedules)): ?>
            <div class="driver-card" style="text-align: center; padding: 40px 20px;">
                <i class="far fa-calendar-times" style="font-size: 3rem; color: #e0e0e0; margin-bottom: 15px;"></i>
                <h3 style="margin: 0; color: #555;">No Upcoming Trips</h3>
                <p style="color: #999; font-size: 0.9rem;">You are all caught up!</p>
            </div>
        <?php else: ?>

            <?php foreach ($groupedSchedules as $date => $trips): ?>
                <?php 
                    $displayDate = date('D, j M', strtotime($date));
                    if ($date === $today) $displayDate = "Today";
                    elseif ($date === date('Y-m-d', strtotime('+1 day'))) $displayDate = "Tomorrow";
                ?>
                
                <div class="date-header"><?= $displayDate ?></div>

                <?php foreach ($trips as $trip): ?>
                    <a href="trip_details.php?id=<?= $trip['id'] ?>" class="schedule-card">
                        
                        <div class="time-col">
                            <div class="time-big"><?= date('H:i', strtotime($trip['departure_time'])) ?></div>
                            <div class="time-ampm"><?= date('A', strtotime($trip['departure_time'])) ?></div>
                        </div>

                        <div class="info-col">
                            <div class="route-title"><?= htmlspecialchars($trip['route_name']) ?></div>
                            
                            <div class="route-dest" style="margin-bottom: 5px;">
                                <i class="fas fa-map-pin" style="color: var(--danger); font-size: 0.8rem;"></i>
                                <?= htmlspecialchars($trip['dest_name']) ?>
                            </div>

                            <div style="font-size: 0.75rem; color: #777;">
                                <i class="fas fa-bus" style="margin-right: 4px;"></i> <?= htmlspecialchars($trip['shuttle_id']) ?>
                                <span style="margin: 0 5px;">•</span>
                                <i class="fas fa-map-signs" style="margin-right: 4px;"></i> <?= ($trip['total_stops'] ?? 0) ?> Stops
                            </div>
                        </div>

                        <div style="color: #ccc;">
                            <i class="fas fa-chevron-right"></i>
                        </div>

                    </a>
                <?php endforeach; ?>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <?php include 'driver_navbar.php'; ?>

</body>
</html>