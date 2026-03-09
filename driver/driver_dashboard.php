<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur'); 
require_once '../config.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$driverId = $_SESSION['user_id'];

// 2. Fetch Driver Data
$driverSnap = $firestore->database()->collection('Staffs')->document($driverId)->snapshot();
$driverData = $driverSnap->data();
$isOnline = ($driverData['status'] ?? 'inactive') === 'active';

// GATEKEEPER
$currentPic = $driverData['profile_pic'] ?? 'default.png';
if (empty($currentPic) || $currentPic === 'default.png') {
    $_SESSION['force_profile_setup'] = true;
    header('Location: driver_profile.php'); 
    exit();
}

// 3. FETCH ASSIGNED SHUTTLE & ZONE
$assignedShuttleInfo = "None";
$assignedZoneInfo = "None";
$shuttleId = $driverData['assigned_shuttle_id'] ?? '';

if (!empty($shuttleId)) {
    $assignedShuttleInfo = $shuttleId;
    $shuttleSnap = $firestore->database()->collection('Shuttles')->document($shuttleId)->snapshot();
    if ($shuttleSnap->exists()) {
        $sData = $shuttleSnap->data();
        $zoneId = $sData['zone_id'] ?? '';
        if (!empty($zoneId)) {
            $zoneSnap = $firestore->database()->collection('Zones')->document($zoneId)->snapshot();
            if ($zoneSnap->exists()) {
                $assignedZoneInfo = $zoneSnap->data()['name'] ?? $zoneId;
            }
        }
    }
}

// ===================================================================================
// LOGIC: FETCH DATA 
// ===================================================================================

// A. ON-DEMAND DATA (Updated for Bookings Collection)
$onDemandJob = null;
$odType = 'none'; 

if ($isOnline) {
    // Check for Active On-Demand Job assigned to this driver
    // Status flow: confirmed (admin assigned) -> arriving (driver started) -> onboard (picked up) -> completed
    $activeQuery = $firestore->database()->collection('Bookings')
        ->where('driver_id', '=', $driverId)
        ->where('type', '=', 'ondemand')
        ->where('status', 'in', ['confirmed', 'arriving', 'onboard']) 
        ->limit(1)->documents();

    foreach ($activeQuery as $doc) {
        $onDemandJob = $doc->data();
        $onDemandJob['id'] = $doc->id();
        $odType = 'active';
        
        // Fetch Names for locations if they are IDs
        $pId = $onDemandJob['pickup_stop_id'] ?? '';
        $dId = $onDemandJob['dropoff_stop_id'] ?? '';
        
        // Quick fetch (in production, cache stops)
        $pSnap = $firestore->database()->collection('Stops')->document($pId)->snapshot();
        $dSnap = $firestore->database()->collection('Stops')->document($dId)->snapshot();
        
        $onDemandJob['pickup_location'] = $pSnap->exists() ? ($pSnap->data()['name'] ?? $pId) : 'Current Location';
        $onDemandJob['destination_name'] = $dSnap->exists() ? ($dSnap->data()['name'] ?? $dId) : 'Destination';
        
        break;
    }

    if (!$onDemandJob) {
        $odType = 'idle';
    }
} else {
    $odType = 'offline';
}

// B. SCHEDULE DATA (Unchanged logic)
$scheduledJob = null;
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$currentTime = date('H:i');
$currentTimestamp = time();

$schedules = $firestore->database()->collection('Schedules')
    ->where('driver_id', '=', $driverId)
    ->where('date', '>=', $today)
    ->orderBy('date', 'ASC')
    ->orderBy('departure_time', 'ASC')
    ->limit(10)
    ->documents();

foreach ($schedules as $s) { 
    $temp = $s->data();
    $sStatus = $temp['status'] ?? 'scheduled';
    if ($sStatus === 'completed') continue;
    
    $jobDateTimeString = $temp['date'] . ' ' . $temp['departure_time'];
    $jobTimestamp = strtotime($jobDateTimeString);
    $isPast = ($jobTimestamp < $currentTimestamp);

    if ($sStatus !== 'active' && $isPast) continue;

    $scheduledJob = $temp;
    $scheduledJob['schedule_id'] = $s->id(); 
    break; 
}

if ($scheduledJob) {
    // ... (Keep existing fetch logic for route/stops names) ...
    $rSnap = $firestore->database()->collection('Routes')->document($scheduledJob['route_id'])->snapshot();
    $scheduledJob['route_name'] = $rSnap->exists() ? $rSnap->data()['route_name'] : $scheduledJob['route_id'];
    
    $destId = $scheduledJob['end_stop_id'] ?? '';
    if (empty($destId) && $rSnap->exists()) {
        $stops = $rSnap->data()['stop_ids'] ?? [];
        if (!empty($stops)) $destId = end($stops);
    }
    if (!empty($destId)) {
        $destSnap = $firestore->database()->collection('Stops')->document($destId)->snapshot();
        $dData = $destSnap->exists() ? $destSnap->data() : [];
        $scheduledJob['destination_name'] = $dData['stop_name'] ?? $dData['name'] ?? $destId;
    } else {
        $scheduledJob['destination_name'] = "Destination";
    }

    $schedDate = $scheduledJob['date'];
    $schedTime = $scheduledJob['departure_time'];
    $schedStatus = $scheduledJob['status'] ?? 'scheduled';

    if ($schedDate === $today) {
        $scheduledJob['date_label'] = "Today";
        $scheduledJob['is_today'] = true;
    } elseif ($schedDate === $tomorrow) {
        $scheduledJob['date_label'] = "Tomorrow";
        $scheduledJob['is_today'] = false;
    } else {
        $scheduledJob['date_label'] = date('d M', strtotime($schedDate));
        $scheduledJob['is_today'] = false;
    }

    $scheduledJob['is_overdue'] = false;
    if ($scheduledJob['is_today'] && $schedTime < $currentTime && $schedStatus !== 'active') {
        $scheduledJob['is_overdue'] = true;
    }

    $scheduledJob['is_ongoing'] = ($schedStatus === 'active');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Home</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css"> 
    <?php if($odType === 'idle'): ?><meta http-equiv="refresh" content="15"><?php endif; ?>
</head>
<body class="driver-body" style="background-color: #f8f9fc;">

    <div class="driver-header" style="padding-bottom: 60px;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <div>
                <div style="font-size:0.85rem; opacity:0.8; font-weight:300;">Welcome back,</div>
                <div style="font-size:1.2rem; font-weight:600;"><?= htmlspecialchars($driverData['full_name']) ?></div>
            </div>
            <div class="status-pill" onclick="toggleStatus()">
                <div id="statusDot" class="status-dot" style="background: <?= $isOnline ? '#2ecc71' : '#bdc3c7' ?>; box-shadow: 0 0 8px <?= $isOnline ? '#2ecc71' : 'transparent' ?>;"></div>
                <span id="statusText" style="font-size:0.85rem; font-weight:500;"><?= $isOnline ? 'Online' : 'Offline' ?></span>
            </div>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -40px;">
        
        <div class="driver-card" style="background: white; border-left: 4px solid var(--primary-blue); padding: 15px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <div style="font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px;">Assignment</div>
                <div style="font-weight: 600; color: var(--primary-blue); font-size: 1rem;">
                    <i class="fas fa-bus"></i> <?= htmlspecialchars($assignedShuttleInfo) ?>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px;">Zone</div>
                <div style="font-weight: 600; color: var(--primary-blue); font-size: 1rem;">
                    <?= htmlspecialchars($assignedZoneInfo) ?>
                </div>
            </div>
        </div>

        <div class="dashboard-section">
            <div class="section-header" style="text-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                <span><i class="fas fa-satellite-dish" style="margin-right: 8px;"></i>On-Demand Status</span>
            </div>

            <?php if ($odType === 'offline'): ?>
                <div class="status-card">
                    <div class="pulse-icon" style="background:#f1f1f1; color:#aaa;"><i class="fas fa-power-off"></i></div>
                    <h3 style="margin:0 0 5px; font-size:1.1rem;">You are Offline</h3>
                    <p style="color:#888; font-size:0.9rem; margin:0;">Go online to receive requests.</p>
                </div>

            <?php elseif ($odType === 'idle'): ?>
                <div class="status-card">
                    <div class="pulse-icon active"><i class="fas fa-radar"></i></div>
                    <h3 style="margin:0 0 5px; font-size:1.1rem; color:var(--primary-blue);">Scanning...</h3>
                    <p style="color:#888; font-size:0.9rem; margin:0;">Waiting for assignments...</p>
                </div>

            <?php elseif ($odType === 'active'): ?>
                <div class="schedule-ticket" style="border: 2px solid var(--accent-yellow);">
                    <div class="ticket-top" style="background:#fff8e1;">
                        <div class="ticket-time-box" style="background:white; color:var(--accent-yellow);">
                            <div><i class="fas fa-bolt"></i></div>
                        </div>
                        <div class="ticket-details">
                            <h4>On-Demand Request</h4>
                            <p style="color:#f57c00; font-weight:600;">
                                <?php 
                                    $st = $onDemandJob['status'];
                                    if($st == 'confirmed') echo "New Job Assigned!";
                                    elseif($st == 'arriving') echo "Heading to Pickup...";
                                    elseif($st == 'onboard') echo "Passenger Onboard";
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <div style="padding: 15px; background: white; border-bottom: 1px solid #eee;">
                        <div style="font-size:0.9rem; margin-bottom:8px;">
                            <span style="color:green;">●</span> <b>From:</b> <?= htmlspecialchars($onDemandJob['pickup_location']) ?>
                        </div>
                        <div style="font-size:0.9rem;">
                            <span style="color:red;">📍</span> <b>To:</b> <?= htmlspecialchars($onDemandJob['destination_name']) ?>
                        </div>
                    </div>

                    <div class="ticket-action" style="background: white;">
                        <?php if(($onDemandJob['status'] ?? '') === 'confirmed'): ?>
                            <a href="ondemand_active.php?id=<?= $onDemandJob['id'] ?>&status=arriving" class="btn-compact" style="background:var(--primary-blue);">
                                Start Job <i class="fas fa-location-arrow" style="margin-left:5px;"></i>
                            </a>
                        <?php elseif(($onDemandJob['status'] ?? '') === 'arriving'): ?>
                            <a href="ondemand_active.php?id=<?= $onDemandJob['id'] ?>&status=onboard" class="btn-compact" style="background:#f39c12;">
                                Passenger Picked Up <i class="fas fa-user-check" style="margin-left:5px;"></i>
                            </a>
                        <?php else: ?>
                            <a href="ondemand_active.php?id=<?= $onDemandJob['id'] ?>&status=completed" class="btn-compact" style="background:var(--success);">
                                Complete Ride <i class="fas fa-check" style="margin-left:5px;"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-section">
            <div class="section-header">
                <span>Upcoming Schedule</span>
                <a href="driver_schedule.php" style="font-size:0.8rem; color:var(--primary-blue); text-decoration:none;">View All</a>
            </div>

            <?php if ($scheduledJob): ?>
                <div class="schedule-ticket" style="<?= $scheduledJob['is_ongoing'] ? 'border: 2px solid var(--success);' : '' ?>">
                    <div class="ticket-top">
                        <div class="ticket-time-box">
                            <div><?= date('H:i', strtotime($scheduledJob['departure_time'])) ?></div>
                            <div style="font-size: 0.65rem; color: #555; margin-top: 2px;"><?= $scheduledJob['date_label'] ?></div>
                        </div>
                        <div class="ticket-details" style="width: 100%;">
                            <?php if ($scheduledJob['is_overdue']): ?>
                                <div style="color:var(--danger); font-size:0.75rem; font-weight:700; display:flex; align-items:center; gap:5px; margin-bottom:5px;"><i class="fas fa-exclamation-circle"></i> Late Departure</div>
                            <?php endif; ?>
                            <?php if ($scheduledJob['is_ongoing']): ?>
                                <div style="color:var(--success); font-size:0.75rem; font-weight:700; display:flex; align-items:center; gap:5px; margin-bottom:5px;"><span class="pulse-dot" style="width:8px; height:8px; background:var(--success); border-radius:50%; display:inline-block;"></span> Trip in Progress</div>
                            <?php endif; ?>
                            <h4><?= htmlspecialchars($scheduledJob['route_name']) ?></h4>
                            <p><i class="fas fa-location-arrow" style="color:#ccc;"></i> To <?= htmlspecialchars($scheduledJob['destination_name']) ?></p>
                        </div>
                    </div>
                    
                    <div class="ticket-action">
                        <div style="font-size:0.85rem; color:#888; display:flex; align-items:center; gap:6px;">
                            <i class="fas fa-users"></i> <b><?= ($scheduledJob['booked_count'] ?? 0) ?></b> / <?= ($scheduledJob['capacity'] ?? 13) ?>
                        </div>
                        <?php if ($scheduledJob['is_ongoing']): ?>
                            <a href="trip_active.php?id=<?= $scheduledJob['schedule_id'] ?>" class="btn-compact" style="background:var(--success);">
                                Resume Trip <i class="fas fa-play" style="font-size:0.7rem; margin-left:5px;"></i>
                            </a>
                        <?php else: ?>
                            <a href="trip_details.php?id=<?= $scheduledJob['schedule_id'] ?>" class="btn-compact" style="background:#333;">
                                View Details
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="status-card" style="padding: 20px; box-shadow: none; border: 1px dashed #ddd; background: #fafafa;">
                    <p style="color:#aaa; font-size:0.9rem; margin:0;">No upcoming schedules found.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="action-grid" style="grid-template-columns: repeat(4, 1fr); gap: 10px;">
             <a href="driver_schedule.php" class="action-card" style="box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                <i class="fas fa-calendar-alt" style="color:var(--primary-blue);"></i><span>Schedule</span>
            </a>
            <a href="driver_alerts.php" class="action-card" style="box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                <i class="fas fa-bell" style="color:var(--accent-yellow);"></i><span>Alerts</span>
            </a>
             <a href="driver_trip_history.php" class="action-card" style="box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                <i class="fas fa-clock" style="color:#9c27b0;"></i><span>History</span>
            </a>
            <a href="driver_profile.php" class="action-card" style="box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                <i class="fas fa-user" style="color:#607d8b;"></i><span>Profile</span>
            </a>
        </div>
    </div>

    <?php include 'driver_navbar.php'; ?>

    <script>
    function toggleStatus() {
        // Toggle Logic (Keep same)
        const dot = document.getElementById('statusDot');
        const text = document.getElementById('statusText');
        const isOnline = text.innerText === 'Online';
        text.innerText = isOnline ? 'Offline' : 'Online';
        dot.style.background = isOnline ? '#bdc3c7' : '#2ecc71';
        dot.style.boxShadow = isOnline ? 'none' : '0 0 8px #2ecc71';
        fetch('toggle_status.php', { method: 'POST' }).then(() => {
            setTimeout(() => window.location.reload(), 300);
        });
    }
    </script>
</body>
</html>