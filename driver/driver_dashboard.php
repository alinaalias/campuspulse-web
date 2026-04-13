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

// GATEKEEPER - Admin Status Check
if (($driverData['status'] ?? '') !== 'active') {
    // If admin suspended them, log them out or show error
    die("Your account is currently inactive. Please contact the administrator.");
}

// NEW: Check Duty Status for the Shift
$isOnline = ($driverData['duty_status'] ?? 'offline') === 'online';

// GATEKEEPER - Profile Setup
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

// A. ON-DEMAND DATA 
$onDemandJob = null;
$odType = 'none';

if ($isOnline) {
    $activeQuery = $firestore->database()->collection('Bookings')
        ->where('driver_id', '=', $driverId)
        ->where('type', '=', 'ondemand')
        ->where('status', 'in', ['confirmed', 'arriving', 'onboard'])
        ->limit(1)->documents();

    foreach ($activeQuery as $doc) {
        $onDemandJob = $doc->data();
        $onDemandJob['id'] = $doc->id();
        $odType = 'active';

        $pId = $onDemandJob['pickup_stop_id'] ?? '';
        $dId = $onDemandJob['dropoff_stop_id'] ?? '';

        $pSnap = $firestore->database()->collection('Stops')->document($pId)->snapshot();
        $dSnap = $firestore->database()->collection('Stops')->document($dId)->snapshot();

        $onDemandJob['pickup_location'] = $pSnap->exists() ? ($pSnap->data()['name'] ?? $pId) : 'Current Location';
        $onDemandJob['destination_name'] = $dSnap->exists() ? ($dSnap->data()['name'] ?? $dId) : 'Destination';

        break;
    }

    if (!$onDemandJob) {
        $pingQuery = $firestore->database()->collection('Bookings')
            ->where('candidate_driver_id', '=', $driverId)
            ->where('type', '=', 'ondemand')
            ->where('status', '=', 'searching')
            ->limit(1)->documents();

        foreach ($pingQuery as $doc) {
            $onDemandJob = $doc->data();
            $onDemandJob['id'] = $doc->id();
            $odType = 'pinging';

            $pId = $onDemandJob['pickup_stop_id'] ?? '';
            $dId = $onDemandJob['dropoff_stop_id'] ?? '';

            $pSnap = $firestore->database()->collection('Stops')->document($pId)->snapshot();
            $dSnap = $firestore->database()->collection('Stops')->document($dId)->snapshot();

            $onDemandJob['pickup_location'] = $pSnap->exists() ? ($pSnap->data()['name'] ?? $pId) : 'Current Location';
            $onDemandJob['destination_name'] = $dSnap->exists() ? ($dSnap->data()['name'] ?? $dId) : 'Destination';

            break;
        }

        if (!$onDemandJob) {
            $odType = 'idle';
        }
    }
} else {
    $odType = 'offline';
}

// B. SCHEDULE DATA
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
    if ($sStatus === 'completed')
        continue;

    $jobDateTimeString = $temp['date'] . ' ' . $temp['departure_time'];
    $jobTimestamp = strtotime($jobDateTimeString);
    $isPast = ($jobTimestamp < $currentTimestamp);

    if ($sStatus !== 'active' && $isPast)
        continue;

    $scheduledJob = $temp;
    $scheduledJob['schedule_id'] = $s->id();
    break;
}

if ($scheduledJob) {
    $rSnap = $firestore->database()->collection('Routes')->document($scheduledJob['route_id'])->snapshot();
    $scheduledJob['route_name'] = $rSnap->exists() ? $rSnap->data()['route_name'] : $scheduledJob['route_id'];

    $etas = $scheduledJob['etas'] ?? [];
    if (!empty($etas)) {
        $scheduledJob['total_stops'] = count($etas);
    }

    $destId = $scheduledJob['end_stop_id'] ?? '';
    if (empty($destId) && $rSnap->exists()) {
        $stops = $rSnap->data()['stop_ids'] ?? [];
        if (!empty($stops))
            $destId = end($stops);
    }
    
    if (!empty($destId)) {
        $destSnap = $firestore->database()->collection('Stops')->document($destId)->snapshot();
        $dData = $destSnap->exists() ? $destSnap->data() : [];
        $destName = $dData['stop_name'] ?? $dData['name'] ?? $destId;
        
        if (!empty($etas) && isset($etas[$destId])) {
            $destName .= ' (' . $etas[$destId] . ')';
        }
        $scheduledJob['destination_name'] = $destName;
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

// C. FETCH UNREAD ALERTS COUNT
$newAlertsCount = 0;
// Get the timestamp of when the driver last opened the alerts page
$lastReadTime = $driverData['last_alert_read_time'] ?? 0;

$alertsQuery = $firestore->database()->collection('Announcements')
    ->where('status', 'in', ['active', 'scheduled'])
    ->documents();

foreach ($alertsQuery as $doc) {
    $aData = $doc->data();
    $audience = $aData['target_audience'] ?? 'all';

    // Only count alerts meant for drivers
    if ($audience !== 'driver' && $audience !== 'all')
        continue;

    // Determine when it was actually published
    $publishTime = !empty($aData['schedule_time']) ? strtotime($aData['schedule_time']) : strtotime($aData['created_at']);

    // Skip if it hasn't been published yet or has already expired
    if ($publishTime > $currentTimestamp)
        continue;
    if (!empty($aData['expires_at']) && strtotime($aData['expires_at']) <= $currentTimestamp)
        continue;

    // If published AFTER the driver last checked, count it!
    if ($publishTime > $lastReadTime) {
        $newAlertsCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Dashboard</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">

    <style>
        .duty-toggle-container {
            background:
                <?= $isOnline ? 'var(--primary-blue)' : '#444' ?>
            ;
            padding: 30px 20px 60px 20px;
            color: white;
            text-align: center;
            transition: background 0.3s ease;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
        }

        .toggle-switch-large {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 20px;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* FAB and other styles kept from your original CSS */
        .fab-report {
            position: fixed;
            bottom: 90px;
            right: 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 20px;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
            cursor: grab;
            z-index: 999;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s;
            touch-action: none;
        }

        .fab-report:active {
            cursor: grabbing;
            transform: scale(0.95);
        }

        .report-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: 0.3s ease;
        }

        .report-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .report-bottom-sheet {
            position: fixed;
            bottom: -100%;
            left: 0;
            width: 100%;
            background: #f8f9fa;
            border-top-left-radius: 24px;
            border-top-right-radius: 24px;
            box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15);
            padding: 15px 20px 40px;
            z-index: 1001;
            transition: bottom 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .report-bottom-sheet.active {
            bottom: 0;
        }

        .sheet-drag-handle {
            width: 40px;
            height: 5px;
            background: #dee2e6;
            border-radius: 5px;
            margin: 0 auto 20px;
        }

        .location-card {
            background: white;
            border: 1px solid #eee;
            padding: 12px 15px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }

        .report-btn {
            background: white;
            border: 2px solid transparent;
            padding: 20px 10px;
            border-radius: 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 100%;
            font-family: inherit;
        }

        .report-btn:active {
            transform: scale(0.95);
            border-color: #dee2e6;
        }

        .report-btn .icon-circle {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .btn-emergency {
            color: #333;
            font-weight: 600;
        }

        .btn-emergency .icon-circle {
            background: #ffebee;
            color: #dc3545;
        }

        .btn-warning {
            color: #333;
            font-weight: 600;
        }

        .btn-warning .icon-circle {
            background: #fff8e1;
            color: #f39c12;
        }
    </style>
</head>

<body class="driver-body" style="background-color: #f4f6f9;">

    <div class="driver-header" style="padding-bottom: 60px;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">

            <div>
                <div style="font-size:0.85rem; opacity:0.8; font-weight:300;">Welcome back,</div>
                <div style="font-size:1.2rem; font-weight:600;">
                    <?= htmlspecialchars(explode(' ', trim($driverData['full_name']))[0]) ?>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 15px;">

                <a href="driver_alerts.php"
                    style="color: white; position: relative; text-decoration: none; margin-right: 10px;">
                    <i class="fas fa-bell" style="font-size: 1.3rem;"></i>

                    <?php if ($newAlertsCount > 0): ?>
                        <span
                            style="position: absolute; top: -6px; right: -8px; background: #e74c3c; color: white; font-size: 0.65rem; font-weight: bold; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--primary-blue); box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                            <?= $newAlertsCount > 9 ? '9+' : $newAlertsCount ?>
                        </span>
                    <?php endif; ?>
                </a>

                <div class="status-pill" onclick="toggleStatus()"
                    style="background: rgba(255,255,255,0.15); padding: 6px 12px; border-radius: 20px; display: flex; align-items: center; gap: 8px; cursor: pointer; border: 1px solid rgba(255,255,255,0.2);">

                    <div id="statusDot" class="status-dot"
                        style="width: 10px; height: 10px; border-radius: 50%; background: <?= $isOnline ? '#2ecc71' : '#bdc3c7' ?>; box-shadow: 0 0 8px <?= $isOnline ? '#2ecc71' : 'transparent' ?>;">
                    </div>

                    <span id="statusText" style="font-size:0.85rem; font-weight:600; color: white;">
                        <?= $isOnline ? 'Online' : 'Offline' ?>
                    </span>
                </div>

            </div>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -30px; padding-bottom: 100px;">

        <div class="driver-card"
            style="background: white; border-left: 4px solid var(--primary-blue); padding: 15px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <div style="font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px;">Vehicle
                </div>
                <div style="font-weight: 600; color: var(--primary-blue); font-size: 1rem;">
                    <i class="fas fa-bus"></i> <?= htmlspecialchars($assignedShuttleInfo) ?>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px;">
                    Operating Zone</div>
                <div style="font-weight: 600; color: var(--primary-blue); font-size: 1rem;">
                    <?= htmlspecialchars($assignedZoneInfo) ?>
                </div>
            </div>
        </div>

        <div class="dashboard-section">
            <div class="section-header" style="text-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                <span><i class="fas fa-satellite-dish" style="margin-right: 8px;"></i>On-Demand Radar</span>
            </div>

            <?php if ($odType === 'offline'): ?>
                <div class="status-card" style="opacity: 0.7;">
                    <div class="pulse-icon" style="background:#f1f1f1; color:#aaa;"><i class="fas fa-moon"></i></div>
                    <h3 style="margin:0 0 5px; font-size:1.1rem;">Offline Mode</h3>
                    <p style="color:#888; font-size:0.9rem; margin:0;">Toggle status above to receive jobs.</p>
                </div>
            <?php elseif ($odType === 'idle'): ?>
                <div class="status-card">
                    <div class="pulse-icon active"><i class="fas fa-radar"></i></div>
                    <h3 style="margin:0 0 5px; font-size:1.1rem; color:var(--primary-blue);">Scanning Area...</h3>
                    <p style="color:#888; font-size:0.9rem; margin:0;">Waiting for student requests.</p>
                </div>
            <?php elseif ($odType === 'pinging'): ?>
                <div class="schedule-ticket" id="pinging-ticket-ui" style="border: 2px solid #2ecc71; animation: pulseGlow 1.5s infinite alternate;">
                    <style>
                        @keyframes pulseGlow {
                            from { box-shadow: 0 0 10px rgba(46, 204, 113, 0.4); }
                            to { box-shadow: 0 0 25px rgba(46, 204, 113, 0.8); }
                        }
                    </style>
                    <div class="ticket-top" style="background:#e8f8f5;">
                        <div class="ticket-time-box" style="background:white; color:#2ecc71;">
                            <div><i class="fas fa-bell"></i></div>
                        </div>
                        <div class="ticket-details">
                            <h4 style="color:#27ae60;">NEW RIDE REQUEST!</h4>
                            <p style="color:#555; font-weight:600;">Nearest Passenger Match</p>
                        </div>
                    </div>
                    
                    <div style="padding: 15px; background: white; border-bottom: 1px solid #eee;">
                        <div style="font-size:0.9rem; margin-bottom:8px;">
                            <span style="color:green;">●</span> <b>Pickup:</b> <?= htmlspecialchars($onDemandJob['pickup_location']) ?>
                        </div>
                        <div style="font-size:0.9rem;">
                            <span style="color:red;">📍</span> <b>Dropoff:</b> <?= htmlspecialchars($onDemandJob['destination_name']) ?>
                        </div>
                    </div>
                    
                    <div class="ticket-action" style="background: white; padding: 15px;">
                        <button onclick="acceptOnDemandJob('<?= $onDemandJob['id'] ?>', this)" class="btn-save" style="width:100%; background:#2ecc71; padding: 12px; font-size: 1.1rem;">
                            ACCEPT JOB
                        </button>
                    </div>
                </div>
                
                <script>
                    function acceptOnDemandJob(bookingId, btn) {
                        btn.style.opacity = '0.5';
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Securing Match...';
                        
                        const formData = new FormData();
                        formData.append('booking_id', bookingId);
                        
                        fetch('accept_booking.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            if(data.success) {
                                window.location.reload();
                            } else {
                                alert("Failed: " + (data.message || 'Error accepting job.'));
                                btn.style.opacity = '1';
                                btn.innerHTML = 'ACCEPT JOB';
                            }
                        })
                        .catch(err => {
                            alert('Network Error');
                            btn.style.opacity = '1';
                            btn.innerHTML = 'ACCEPT JOB';
                        });
                    }
                </script>
            <?php elseif ($odType === 'active'): ?>
                <div class="schedule-ticket" style="border: 2px solid var(--accent-yellow);">
                    <div class="ticket-top" style="background:#fff8e1;">
                        <div class="ticket-time-box" style="background:white; color:var(--accent-yellow);">
                            <div><i class="fas fa-bolt"></i></div>
                        </div>
                        <div class="ticket-details">
                            <h4>Active Job</h4>
                            <p style="color:#f57c00; font-weight:600;">
                                <?php
                                if ($onDemandJob['status'] == 'confirmed')
                                    echo "New Pickup Assigned!";
                                elseif ($onDemandJob['status'] == 'arriving')
                                    echo "Heading to Pickup...";
                                elseif ($onDemandJob['status'] == 'onboard')
                                    echo "Passenger Onboard";
                                ?>
                            </p>
                        </div>
                    </div>

                    <div style="padding: 15px; background: white; border-bottom: 1px solid #eee;">
                        <div style="font-size:0.9rem; margin-bottom:8px;">
                            <span style="color:green;">●</span> <b>From:</b>
                            <?= htmlspecialchars($onDemandJob['pickup_location']) ?>
                        </div>
                        <div style="font-size:0.9rem;">
                            <span style="color:red;">📍</span> <b>To:</b>
                            <?= htmlspecialchars($onDemandJob['destination_name']) ?>
                        </div>
                    </div>

                    <div class="ticket-action" style="background: white;">
                        <?php if ($onDemandJob['status'] === 'confirmed'): ?>
                            <a href="ondemand_active.php?id=<?= $onDemandJob['id'] ?>&status=arriving" class="btn-compact"
                                style="background:var(--primary-blue);">
                                Start Job <i class="fas fa-location-arrow" style="margin-left:5px;"></i>
                            </a>
                        <?php elseif ($onDemandJob['status'] === 'arriving'): ?>
                            <a href="ondemand_active.php?id=<?= $onDemandJob['id'] ?>&status=onboard" class="btn-compact"
                                style="background:#f39c12;">
                                Picked Up <i class="fas fa-user-check" style="margin-left:5px;"></i>
                            </a>
                        <?php else: ?>
                            <a href="ondemand_active.php?id=<?= $onDemandJob['id'] ?>&status=completed" class="btn-compact"
                                style="background:var(--success);">
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
            </div>

            <?php if ($scheduledJob): ?>
                <div class="schedule-ticket"
                    style="<?= $scheduledJob['is_ongoing'] ? 'border: 2px solid var(--success);' : '' ?>">
                    <div class="ticket-top">
                        <div class="ticket-time-box">
                            <div><?= date('H:i', strtotime($scheduledJob['departure_time'])) ?></div>
                            <div style="font-size: 0.65rem; color: #555; margin-top: 2px;">
                                <?= $scheduledJob['date_label'] ?>
                            </div>
                        </div>
                        <div class="ticket-details" style="width: 100%;">
                            <?php if ($scheduledJob['is_overdue']): ?>
                                <div
                                    style="color:var(--danger); font-size:0.75rem; font-weight:700; display:flex; align-items:center; gap:5px; margin-bottom:5px;">
                                    <i class="fas fa-exclamation-circle"></i> Late Departure
                                </div>
                            <?php endif; ?>
                            <?php if ($scheduledJob['is_ongoing']): ?>
                                <div
                                    style="color:var(--success); font-size:0.75rem; font-weight:700; display:flex; align-items:center; gap:5px; margin-bottom:5px;">
                                    <span class="pulse-dot"
                                        style="width:8px; height:8px; background:var(--success); border-radius:50%; display:inline-block;"></span>
                                    Trip in Progress
                                </div>
                            <?php endif; ?>
                            <h4><?= htmlspecialchars($scheduledJob['route_name']) ?></h4>
                            <p><i class="fas fa-location-arrow" style="color:#ccc;"></i> To
                                <?= htmlspecialchars($scheduledJob['destination_name']) ?>
                            </p>
                        </div>
                    </div>

                    <div class="ticket-action">
                        <div style="font-size:0.85rem; color:#888; display:flex; align-items:center; gap:6px;">
                            <i class="fas fa-users"></i> <b><?= ($scheduledJob['booked_count'] ?? 0) ?></b> /
                            <?= ($scheduledJob['capacity'] ?? 13) ?>
                        </div>
                        <?php if ($scheduledJob['is_ongoing']): ?>
                            <a href="trip_active.php?id=<?= $scheduledJob['schedule_id'] ?>" class="btn-compact"
                                style="background:var(--success);">
                                Resume Trip <i class="fas fa-play" style="font-size:0.7rem; margin-left:5px;"></i>
                            </a>
                        <?php else: ?>
                            <a href="trip_details.php?id=<?= $scheduledJob['schedule_id'] ?>" class="btn-compact"
                                style="background:#333;">
                                View Details
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="status-card"
                    style="padding: 20px; box-shadow: none; border: 1px dashed #ddd; background: #fafafa;">
                    <p style="color:#aaa; font-size:0.9rem; margin:0;">No upcoming schedules found.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <button class="fab-report" id="draggableFab" onclick="toggleReportSheet(event)">
        <i class="fas fa-broadcast-tower"></i> Live Report
    </button>

    <div class="report-overlay" id="reportOverlay" onclick="toggleReportSheet()"></div>

    <div class="report-bottom-sheet" id="reportSheet">
        <div class="sheet-drag-handle"></div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
            <h3 style="margin:0; font-size: 1.2rem; color: #333; font-weight: 700;">Live Service Report</h3>
            <button onclick="toggleReportSheet()"
                style="background: rgba(0,0,0,0.05); border: none; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #555; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="location-card" id="locationCard">
            <div id="locationIcon" style="font-size: 1.2rem; color: #adb5bd;"><i class="fas fa-map-marker-alt"></i>
            </div>
            <div style="flex: 1;">
                <div
                    style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #888; font-weight: 600; margin-bottom: 2px;">
                    Current Location</div>
                <div id="locationStatusText" style="font-weight: 500; color: #333; line-height: 1.2;">Tap to detect
                    location...</div>
            </div>
        </div>

        <form action="process_driver_alert.php" method="POST" id="liveReportForm">
            <input type="hidden" name="detected_location" id="detected_location" value="Unknown Location">
            <input type="hidden" name="lat" id="driver_lat">
            <input type="hidden" name="lng" id="driver_lng">
            <input type="hidden" name="alert_type" id="final_alert_type">

            <div id="step1-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <button type="button" class="report-btn btn-emergency"
                    onclick="selectReportType('breakdown', 'Breakdown', 'fa-tools', '#dc3545', '#ffebee')">
                    <div class="icon-circle"><i class="fas fa-tools"></i></div>
                    <span style="font-size: 0.9rem;">Breakdown</span>
                </button>
                <button type="button" class="report-btn btn-emergency"
                    onclick="selectReportType('accident', 'Accident', 'fa-car-crash', '#dc3545', '#ffebee')">
                    <div class="icon-circle"><i class="fas fa-car-crash"></i></div>
                    <span style="font-size: 0.9rem;">Accident</span>
                </button>
                <button type="button" class="report-btn btn-warning"
                    onclick="selectReportType('traffic', 'Heavy Traffic', 'fa-traffic-light', '#f39c12', '#fff8e1')">
                    <div class="icon-circle"><i class="fas fa-traffic-light"></i></div>
                    <span style="font-size: 0.9rem;">Heavy Traffic</span>
                </button>
                <button type="button" class="report-btn btn-warning"
                    onclick="selectReportType('rain', 'Heavy Rain', 'fa-cloud-showers-heavy', '#f39c12', '#fff8e1')">
                    <div class="icon-circle"><i class="fas fa-cloud-showers-heavy"></i></div>
                    <span style="font-size: 0.9rem;">Heavy Rain</span>
                </button>
            </div>

            <div id="step2-confirm"
                style="display:none; flex-direction:column; gap:15px; animation: slideUp 0.3s ease;">
                <div
                    style="display:flex; align-items:center; gap:15px; padding:15px; background:#f9f9f9; border-radius:12px; border:1px solid #eee;">
                    <div id="confirmIconBg"
                        style="width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.4rem;">
                        <i id="confirmIcon" class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <div style="font-size:0.8rem; color:#888; text-transform:uppercase; font-weight:600;">Confirm
                            Report</div>
                        <div id="confirmTitle" style="font-size:1.2rem; font-weight:700; color:#333;">Category</div>
                    </div>
                </div>
                <div>
                    <label
                        style="font-size:0.85rem; font-weight:600; color:#555; margin-bottom:5px; display:block;">Additional
                        Details (Optional)</label>
                    <input type="text" name="alert_details" id="alert_details"
                        placeholder="e.g. Flat tire, blocked left lane..."
                        style="width:100%; padding:12px; border:1px solid #ddd; border-radius:8px; font-family:inherit; outline:none;">
                </div>
                <div style="display:flex; gap:10px; margin-top:10px;">
                    <button type="button" onclick="cancelReport()"
                        style="flex:1; padding:15px; background:#eee; color:#555; border:none; border-radius:12px; font-weight:600; cursor:pointer;">
                        Cancel
                    </button>
                    <button type="submit" id="btnSubmitReport"
                        style="flex:2; padding:15px; background:var(--primary-blue); color:white; border:none; border-radius:12px; font-weight:600; cursor:pointer; display:flex; justify-content:center; align-items:center; gap:8px;"
                        onclick="showSendingFinal(this)">
                        <i class="fas fa-paper-plane"></i> Send Alert
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php include 'driver_navbar.php'; ?>

    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>
    <script>
        const driverAssignedShuttle = <?= json_encode($shuttleId) ?>;
        const driverIsOnline = <?= $isOnline ? 'true' : 'false' ?>;

        const firebaseConfig = {
            apiKey: "AIzaSyD_E8JfmScnhsxqW-sBCOfW8kRFdNcrGIk",
            authDomain: "campuspulse-bfd09.firebaseapp.com",
            projectId: "campuspulse-bfd09",
            storageBucket: "campuspulse-bfd09.firebasestorage.app",
            messagingSenderId: "380453135946",
            appId: "1:380453135946:web:00e83d9df74b17c19ba8b3"
        };
        if (!firebase.apps.length) {
            firebase.initializeApp(firebaseConfig);
        }
    </script>
    <script src="driver_dashboard.js"></script>
</body>

</html>