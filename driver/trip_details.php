<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$scheduleId = $_GET['id'] ?? '';
if (!$scheduleId) {
    header('Location: driver_dashboard.php');
    exit();
}

// Fetch Schedule
$schedRef = $firestore->database()->collection('Schedules')->document($scheduleId);
$schedSnap = $schedRef->snapshot();
if (!$schedSnap->exists()) {
    echo "Trip not found.";
    exit();
}
$trip = $schedSnap->data();

$routeRef = $firestore->database()->collection('Routes')->document($trip['route_id']);
$routeSnap = $routeRef->snapshot();
$rData = $routeSnap->exists() ? $routeSnap->data() : [];
$stopsList = [];
$etas = $trip['etas'] ?? [];

if (!empty($etas)) {
    // 1. Dynamic ETA-based routing (New System)
    asort($etas);
    foreach ($etas as $sid => $time) {
        $sSnap = $firestore->database()->collection('Stops')->document($sid)->snapshot();
        $sName = $sid;
        if ($sSnap->exists()) {
            $sName = $sSnap->data()['name'] ?? $sid;
        }
        $stopsList[] = $time . ' - ' . $sName;
    }
} else {
    // 2. Fallback to older Sequential Static Routing
    $stopIds = $rData['stop_ids'] ?? [];

    // Direction Check
    $scheduledStart = $trip['start_stop_id'];
    $scheduledEnd = $trip['end_stop_id'];
    if (!empty($stopIds) && (end($stopIds) === $scheduledStart || reset($stopIds) === $scheduledEnd)) {
        $stopIds = array_reverse($stopIds);
    }

    // Fetch Stop Names
    foreach ($stopIds as $sid) {
        $sSnap = $firestore->database()->collection('Stops')->document($sid)->snapshot();
        $sName = $sid;
        if ($sSnap->exists())
            $sName = $sSnap->data()['name'] ?? $sid;
        $stopsList[] = $sName;
    }
}

// Logic: Can Start?
$today = date('Y-m-d');
$canStart = ($trip['date'] === $today);
$isFuture = ($trip['date'] > $today);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Trip Details</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .detail-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stop-list {
            padding-left: 20px;
            border-left: 2px solid #eee;
            margin-left: 10px;
        }

        .stop-item {
            position: relative;
            padding-bottom: 20px;
            padding-left: 15px;
        }

        .stop-item::before {
            content: '';
            position: absolute;
            left: -21px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-blue);
            border: 2px solid white;
        }

        .stop-item:last-child {
            padding-bottom: 0;
        }

        .stop-item:last-child::before {
            background: var(--accent-yellow);
        }
    </style>
</head>

<body class="driver-body">
    <div class="driver-header">
        <a href="driver_dashboard.php" style="color:white;"><i class="fas fa-arrow-left"></i></a>
        <h3 style="color:white; margin:0;">Trip Overview</h3>
        <div style="width:20px;"></div>
    </div>

    <div class="driver-container">

        <div class="detail-card" style="text-align: center;">
            <div style="font-size: 2rem; font-weight: 700; color: var(--primary-blue);">
                <?= date('H:i', strtotime($trip['departure_time'])) ?></div>
            <div style="color: #666;"><?= date('l, d M Y', strtotime($trip['date'])) ?></div>
            <div style="margin-top: 10px; font-weight: 600;">
                <?= htmlspecialchars($rData['route_name'] ?? $trip['route_id']) ?></div>
        </div>

        <div class="detail-card">
            <h4 style="margin-top:0; color:#555;">Vehicle</h4>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div><i class="fas fa-bus"></i> <?= htmlspecialchars($trip['shuttle_id']) ?></div>
                <div><i class="fas fa-users"></i> Cap: <?= htmlspecialchars($trip['capacity'] ?? 13) ?></div>
            </div>
        </div>

        <div class="detail-card">
            <h4 style="margin-top:0; color:#555; margin-bottom: 20px;">Route Stops</h4>
            <div class="stop-list">
                <?php foreach ($stopsList as $stopName): ?>
                    <div class="stop-item">
                        <div style="font-weight: 500;"><?= htmlspecialchars($stopName) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($canStart): ?>
            <a href="trip_active.php?id=<?= $scheduleId ?>" class="btn-save"
                style="text-decoration:none; display:flex; justify-content:center; align-items:center;">
                Start Trip <i class="fas fa-play" style="margin-left: 10px;"></i>
            </a>
        <?php elseif ($isFuture): ?>
            <button class="btn-save" disabled style="background: #ccc; cursor: not-allowed;">
                Scheduled for <?= date('d M', strtotime($trip['date'])) ?>
            </button>
        <?php else: ?>
            <button class="btn-save" disabled style="background: #ccc; cursor: not-allowed;">
                Trip Date Passed
            </button>
        <?php endif; ?>

    </div>
</body>

</html>