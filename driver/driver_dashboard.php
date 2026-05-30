<?php
session_set_cookie_params(2592000);
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$driverId = $_SESSION['user_id'];


$driverSnap = $firestore->collection('Staffs')->document($driverId)->snapshot();
$driverData = $driverSnap->data();


$todayDate = new DateTime('today');
$licExp = $driverData['license_expiry'] ?? '';
$psvExp = $driverData['psv_expiry'] ?? '';
$licDays = !empty($licExp) ? (int) $todayDate->diff(new DateTime($licExp))->format('%r%a') : null;
$psvDays = !empty($psvExp) ? (int) $todayDate->diff(new DateTime($psvExp))->format('%r%a') : null;
$isExpired = (($licDays !== null && $licDays < 0) || ($psvDays !== null && $psvDays < 0));
$status = $driverData['status'] ?? '';

if ($status === 'suspended' || $status === 'inactive' || $status === 'pending_review' || $isExpired) {
    $_SESSION['requires_compliance_update'] = true;
    header('Location: driver_profile.php');
    exit();
} else {
    unset($_SESSION['requires_compliance_update']);
}

$isOnline = ($driverData['duty_status'] ?? 'offline') === 'online';


$currentPic = $driverData['profile_pic'] ?? 'default.png';
if (empty($currentPic) || $currentPic === 'default.png') {
    $_SESSION['force_profile_setup'] = true;
    header('Location: driver_profile.php');
    exit();
}


$assignedShuttleInfo = "None";
$assignedZoneInfo = "None";
$shuttleId = $driverData['assigned_shuttle_id'] ?? '';
$shuttleStatus = 'active'; // Default to active

if (!empty($shuttleId)) {
    $assignedShuttleInfo = $shuttleId;
    $shuttleSnap = $firestore->collection('Shuttles')->document($shuttleId)->snapshot();
    if ($shuttleSnap->exists()) {
        $sData = $shuttleSnap->data();
        $shuttleStatus = $sData['status'] ?? 'active'; // Fetch actual status
        $zoneId = $sData['zone_id'] ?? '';
        if (!empty($zoneId)) {
            $zoneSnap = $firestore->collection('Zones')->document($zoneId)->snapshot();
            if ($zoneSnap->exists()) {
                $assignedZoneInfo = $zoneSnap->data()['name'] ?? $zoneId;
            }
        }
    }
}

$isMaintenance = (strtolower($shuttleStatus) === 'maintenance');
if ($isMaintenance) {
    $isOnline = false; // Force offline in UI if broken down
}


$onDemandJob = null;
$odType = 'none';

if ($isOnline) {
    $activeQuery = $firestore->collection('Bookings')
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

        $pSnap = $firestore->collection('Stops')->document($pId)->snapshot();
        $dSnap = $firestore->collection('Stops')->document($dId)->snapshot();

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


$scheduledJob = null;
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$currentTime = date('H:i');
$currentTimestamp = time();

$schedules = $firestore->collection('Schedules')
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
    // 15-minute grace buffer: treat jobs as "past" only after 15 mins have elapsed
    $isPast = ($jobTimestamp < ($currentTimestamp - 900));

    if ($sStatus !== 'active' && $isPast)
        continue;

    $scheduledJob = $temp;
    $scheduledJob['schedule_id'] = $s->id();
    break;
}

if ($scheduledJob) {
    $rSnap = $firestore->collection('Routes')->document($scheduledJob['route_id'])->snapshot();
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
        $destSnap = $firestore->collection('Stops')->document($destId)->snapshot();
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


$newAlertsCount = 0;
// Get the timestamp of when the driver last opened the alerts page
$lastReadTime = $driverData['last_alert_read_time'] ?? 0;

$alertsQuery = $firestore->collection('Announcements')
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

// Check personal unread notifications
$notificationsQuery = $firestore->collection('Notifications')
    ->where('user_id', '=', $driverId)
    ->where('is_read', '=', false)
    ->documents();

foreach ($notificationsQuery as $doc) {
    $newAlertsCount++;
}

// Add warning counts for upcoming expirations (<= 30 days) to keep badge aggressive
if ($licDays !== null && $licDays >= 0 && $licDays <= 30) {
    if ($currentTimestamp > $lastReadTime) { // Re-trigger count if unread
        $newAlertsCount++;
    }
}
if ($psvDays !== null && $psvDays >= 0 && $psvDays <= 30) {
    if ($currentTimestamp > $lastReadTime) {
        $newAlertsCount++;
    }
}

$pageTitle = 'Driver Dashboard';
$extraHead = '
<style>
    /* VEHICLE INFO CARD */
    .vehicle-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 18px 20px;
    }

    .v-label {
        font-size: 0.75rem;
        color: #a0aec0;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .v-value {
        font-weight: 700;
        color: var(--primary-blue);
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .v-divider {
        width: 1px;
        height: 40px;
        background: #edf2f7;
        margin: 0 15px;
    }

    /* ON-DEMAND CARDS */
    .status-card {
        text-align: center;
        padding: 40px 20px;
    }

    .pulse-icon {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        margin: 0 auto 15px;
        position: relative;
    }

    .pulse-icon.offline {
        background: #f1f3f5;
        color: #adb5bd;
    }

    .pulse-icon.idle {
        background: rgba(52, 152, 219, 0.1);
        color: var(--primary-blue);
    }

    .pulse-icon.idle::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 2px solid var(--primary-blue);
        animation: ripple 2s infinite ease-out;
    }

    @keyframes ripple {
        0% { transform: scale(1); opacity: 1; }
        100% { transform: scale(1.5); opacity: 0; }
    }

    /* PINGING / JOB CARD */
    .job-card {
        border: 2px solid transparent;
        overflow: hidden;
        padding: 0;
    }

    .job-card.pinging {
        border-color: #2ecc71;
        box-shadow: 0 10px 30px rgba(46, 204, 113, 0.2);
        animation: cardPulse 1.5s infinite alternate;
    }

    @keyframes cardPulse {
        from { box-shadow: 0 10px 30px rgba(46, 204, 113, 0.2); }
        to { box-shadow: 0 10px 40px rgba(46, 204, 113, 0.5); }
    }

    .job-header {
        padding: 15px 20px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .job-header.pinging-bg { background: #e8f8f5; }
    .job-header.active-bg { background: #fff8e1; }

    .icon-box-large {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        background: white;
    }

    .icon-box-large.green {
        color: #27ae60;
        box-shadow: 0 4px 10px rgba(39, 174, 96, 0.1);
    }

    .icon-box-large.yellow {
        color: #f39c12;
        box-shadow: 0 4px 10px rgba(243, 156, 18, 0.1);
    }

    .job-title {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
    }

    .job-subtitle {
        margin: 0;
        font-size: 0.85rem;
        font-weight: 500;
        color: #555;
    }

    .route-path {
        padding: 20px;
        background: white;
    }

    .stop-point {
        display: flex;
        align-items: flex-start;
        gap: 15px;
        margin-bottom: 15px;
        position: relative;
    }

    .stop-point:last-child {
        margin-bottom: 0;
    }

    .stop-point:first-child::after {
        content: "";
        position: absolute;
        left: 7px;
        top: 20px;
        bottom: -15px;
        width: 2px;
        background: #e2e8f0;
        border-radius: 2px;
    }

    .stop-dot {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 0 0 1px #cbd5e0;
        margin-top: 3px;
        position: relative;
        z-index: 2;
    }

    .dot-start { background: #2ecc71; box-shadow: 0 0 0 1px #2ecc71; }
    .dot-end { background: #e74c3c; box-shadow: 0 0 0 1px #e74c3c; }

    .stop-info { flex: 1; }

    .stop-label {
        font-size: 0.75rem;
        color: #a0aec0;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .stop-name {
        font-size: 1.05rem;
        font-weight: 600;
        color: #2d3748;
        line-height: 1.2;
    }

    /* FAB OVERRIDES */
    .fab-report {
        position: fixed;
        bottom: 90px;
        right: 20px;
        background: white;
        color: #e74c3c;
        border: none;
        border-radius: 50px;
        padding: 15px 20px;
        font-size: 1rem;
        font-weight: 700;
        box-shadow: 0 10px 25px rgba(220, 53, 69, 0.25);
        cursor: pointer;
        z-index: 999;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: transform 0.2s;
    }

    .fab-report:active { transform: scale(0.95); }

    /* BOTTOM SHEET */
    .report-overlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: 0.3s;
        backdrop-filter: blur(2px);
    }

    .report-overlay.active { opacity: 1; visibility: visible; }

    .report-bottom-sheet {
        position: fixed;
        bottom: -100%; left: 0; width: 100%;
        background: white;
        border-top-left-radius: 30px;
        border-top-right-radius: 30px;
        box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.1);
        padding: 15px 25px 40px;
        z-index: 1001;
        transition: bottom 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .report-bottom-sheet.active { bottom: 0; }

    .sheet-drag-handle {
        width: 50px;
        height: 6px;
        background: #e2e8f0;
        border-radius: 6px;
        margin: 0 auto 25px;
    }

    .report-btn {
        background: #f8f9fa;
        border: 2px solid transparent;
        padding: 20px 10px;
        border-radius: 20px;
        text-align: center;
        cursor: pointer;
        transition: 0.2s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        width: 100%;
        font-family: inherit;
        box-shadow: none;
    }

    .report-btn:active {
        transform: scale(0.95);
        background: #edf2f7;
    }

    .report-btn .icon-circle {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
    }
</style>';
include '../layout/driver/header.php';
?>

<div class="driver-header">
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
        <div>
            <div style="font-size:0.85rem; opacity:0.85; font-weight:400; margin-bottom:2px;">Welcome back,</div>
            <div style="font-size:1.4rem; font-weight:700; line-height:1;">
                <?= htmlspecialchars(explode(' ', trim($driverData['full_name']))[0]) ?>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 18px;">
            <a href="driver_alerts.php" style="color: white; position: relative; text-decoration: none;">
                <i class="fas fa-bell" style="font-size: 1.4rem;"></i>
                <?php if ($newAlertsCount > 0): ?>
                    <span
                        style="position: absolute; top: -6px; right: -8px; background: #e74c3c; color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--primary-blue);">
                        <?= $newAlertsCount > 9 ? '9+' : $newAlertsCount ?>
                    </span>
                <?php endif; ?>
            </a>

            <?php if ($isMaintenance): ?>
                <div class="status-pill"
                    style="background: rgba(231, 76, 60, 0.15); padding: 8px 16px; border-radius: 30px; display: flex; align-items: center; gap: 10px; border: 1px solid rgba(231, 76, 60, 0.3);">
                    <i class="fas fa-tools" style="color: #e74c3c;"></i>
                    <span style="font-size:0.9rem; font-weight:700; color: #e74c3c;">MAINTENANCE</span>
                </div>
            <?php else: ?>
                <div class="status-pill" onclick="toggleStatus()"
                    style="background: <?= $isOnline ? 'rgba(46, 204, 113, 0.15)' : 'rgba(255,255,255,0.15)' ?>; padding: 8px 16px; border-radius: 30px; display: flex; align-items: center; gap: 10px; cursor: pointer; border: 1px solid <?= $isOnline ? 'rgba(46, 204, 113, 0.3)' : 'rgba(255,255,255,0.2)' ?>;">
                    <div id="statusDot"
                        style="width: 10px; height: 10px; border-radius: 50%; background: <?= $isOnline ? '#2ecc71' : '#bdc3c7' ?>; box-shadow: 0 0 8px <?= $isOnline ? '#2ecc71' : 'transparent' ?>;">
                    </div>
                    <span id="statusText"
                        style="font-size:0.9rem; font-weight:700; color: <?= $isOnline ? '#2ecc71' : 'white' ?>;">
                        <?= $isOnline ? 'ONLINE' : 'OFFLINE' ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="driver-container">

    <div class="app-card vehicle-card">
        <div style="flex: 1;">
            <div class="v-label">Vehicle ID</div>
            <div class="v-value"><i class="fas fa-shuttle-van"></i> <?= htmlspecialchars($assignedShuttleInfo) ?></div>
        </div>
        <div class="v-divider"></div>
        <div style="flex: 1; text-align: right;">
            <div class="v-label">Operating Zone</div>
            <div class="v-value" style="justify-content: flex-end; color: #2d3748;">
                <?= htmlspecialchars($assignedZoneInfo) ?>
            </div>
        </div>
    </div>

    <div class="dashboard-section">
        <div class="section-header">
            <span><i class="fas fa-satellite-dish" style="margin-right: 8px; color:var(--primary-blue);"></i> On-Demand
                Radar</span>
        </div>

        <?php if ($isMaintenance): ?>
            <div id="maintenance-card" class="app-card status-card"
                style="display: block; border-color: #e74c3c; box-shadow: 0 8px 25px rgba(231, 76, 60, 0.15);">
                <div class="pulse-icon" style="background: #fdedec; color: #e74c3c;"><i class="fas fa-tools"></i></div>
                <h3 style="margin:0 0 5px; font-size:1.2rem; font-weight:700; color:#c0392b;">Shuttle Under Maintenance</h3>
                <p style="color:#718096; font-size:0.9rem; margin:0;">Emergency reported. You cannot go online until the
                    admin resolves this issue or replaces your shuttle.</p>
            </div>

        <?php elseif ($odType === 'offline' || $odType === 'idle'): ?>
            <div id="offline-card" class="app-card status-card"
                style="display: <?= $odType === 'offline' ? 'block' : 'none' ?>;">
                <div class="pulse-icon offline"><i class="fas fa-moon"></i></div>
                <h3 style="margin:0 0 5px; font-size:1.2rem; font-weight:700; color:#2d3748;">Offline Mode</h3>
                <p style="color:#718096; font-size:0.9rem; margin:0;">Go Online above to receive incoming ride requests.</p>
            </div>

            <div id="scanning-card" class="app-card status-card"
                style="display: <?= $odType === 'idle' ? 'block' : 'none' ?>;">
                <div class="pulse-icon idle"><i class="fas fa-radar"></i></div>
                <h3 style="margin:0 0 5px; font-size:1.2rem; font-weight:700; color:var(--primary-blue);">Scanning Area...
                </h3>
                <p style="color:#718096; font-size:0.9rem; margin:0;">You are online. Waiting for student requests in your
                    zone.</p>
            </div>

            <div id="pinging-card-container"></div>

        <?php elseif ($odType === 'active'): ?>
            <div class="app-card job-card" style="border-color: #f39c12; box-shadow: 0 8px 25px rgba(243, 156, 18, 0.15);">
                <div class="job-header active-bg">
                    <div class="icon-box-large yellow"><i class="fas fa-bolt"></i></div>
                    <div>
                        <h4 class="job-title">Active Job</h4>
                        <p class="job-subtitle" style="color:#e67e22;">
                            <?php
                            if ($onDemandJob['status'] == 'confirmed')
                                echo "Navigate to Pickup Location";
                            elseif ($onDemandJob['status'] == 'arriving' || $onDemandJob['status'] == 'arrived') // FIX 4: Handle arrived text
                                echo "Passenger Boarding...";
                            elseif ($onDemandJob['status'] == 'onboard')
                                echo "Navigate to Destination";
                            ?>
                        </p>
                    </div>
                </div>

                <div class="action-area">
                    <?php if ($onDemandJob['status'] === 'confirmed'): ?>
                        <a href="active_trip.php?id=BOOK:<?= $onDemandJob['id'] ?>" class="btn-massive blue">
                            START JOB <i class="fas fa-location-arrow"></i>
                        </a>
                    <?php elseif (in_array($onDemandJob['status'], ['arriving', 'arrived'])): ?>
                        <a href="active_trip.php?id=BOOK:<?= $onDemandJob['id'] ?>" class="btn-massive yellow"
                            style="color:#fff;">
                            RESUME RIDE <i class="fas fa-play"></i>
                        </a>
                    <?php else: ?>
                        <a href="active_trip.php?id=BOOK:<?= $onDemandJob['id'] ?>" class="btn-massive green">
                            COMPLETE RIDE <i class="fas fa-flag-checkered"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="dashboard-section">
        <div class="section-header">
            <span><i class="far fa-calendar-alt" style="margin-right: 8px; color:var(--primary-blue);"></i> Upcoming
                Schedule</span>
        </div>

        <?php if ($scheduledJob): ?>
            <div class="sched-ticket <?= $scheduledJob['is_ongoing'] ? 'active-trip' : '' ?>">
                <div class="sched-top">
                    <div class="sched-time-block">
                        <div class="time-big"><?= date('H:i', strtotime($scheduledJob['departure_time'])) ?></div>
                        <div class="time-small"><?= $scheduledJob['date_label'] ?></div>
                    </div>

                    <div class="sched-details">
                        <?php if ($scheduledJob['is_overdue'] && !$scheduledJob['is_ongoing']): ?>
                            <div
                                style="color:#e74c3c; font-size:0.75rem; font-weight:700; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                <i class="fas fa-exclamation-circle"></i> LATE DEPARTURE
                            </div>
                        <?php endif; ?>
                        <?php if ($scheduledJob['is_ongoing']): ?>
                            <div
                                style="color:#27ae60; font-size:0.75rem; font-weight:700; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                                <span
                                    style="width:8px; height:8px; background:#27ae60; border-radius:50%; animation: pulse 2s infinite;"></span>
                                TRIP IN PROGRESS
                            </div>
                        <?php endif; ?>

                        <h4 class="route-title"><?= htmlspecialchars($scheduledJob['route_name']) ?></h4>
                        <p class="route-dest">
                            <i class="fas fa-flag-checkered"></i> <?= htmlspecialchars($scheduledJob['destination_name']) ?>
                        </p>
                    </div>
                </div>

                <div class="action-area">
                    <div class="badge-group">
                        <div class="stat-badge">
                            <i class="fas fa-users" style="color:#3498db;"></i>
                            <?= ($scheduledJob['booked_count'] ?? 0) ?> / <?= ($scheduledJob['capacity'] ?? 13) ?>
                        </div>
                    </div>

                    <?php if ($isMaintenance): ?>
                        <button disabled class="btn-massive red"
                            style="opacity: 0.6; cursor: not-allowed; width: 100%; border: none; padding: 18px; border-radius: 16px; font-size: 1.15rem; font-weight: 700; color: white;">
                            SHUTTLE DISABLED <i class="fas fa-tools"></i>
                        </button>
                    <?php elseif ($scheduledJob['is_ongoing']): ?>
                        <a href="active_trip.php?id=SCHED:<?= $scheduledJob['schedule_id'] ?>" class="btn-pill green">
                            RESUME <i class="fas fa-play"></i>
                        </a>
                    <?php else: ?>
                        <button
                            onclick="previewTrip('SCHED:<?= $scheduledJob['schedule_id'] ?>', '<?= htmlspecialchars(addslashes($scheduledJob['route_name'])) ?>', '<?= date('H:i', strtotime($scheduledJob['departure_time'])) ?>', '<?= $scheduledJob['date'] ?>', '<?= $today ?>', <?= $scheduledJob['is_ongoing'] ? 'true' : 'false' ?>)"
                            class="btn-pill dark">
                            DETAILS <i class="fas fa-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="app-card status-card" style="padding: 30px 20px; background: #f8f9fa;">
                <p style="color:#a0aec0; font-size:1rem; font-weight:500; margin:0;">No upcoming schedules found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<button class="fab-report" id="draggableFab" onclick="toggleReportSheet(event)">
    <i class="fas fa-exclamation-triangle"></i> <span style="font-size:0.9rem;">REPORT</span>
</button>

<div class="report-overlay" id="reportOverlay" onclick="toggleReportSheet()"></div>

<div class="report-bottom-sheet" id="reportSheet">
    <div class="sheet-drag-handle"></div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h3 style="margin:0; font-size: 1.3rem; color: #2d3748; font-weight: 700;">Live Service Report</h3>
        <button onclick="toggleReportSheet()"
            style="background: #f1f3f5; border: none; width: 34px; height: 34px; border-radius: 50%; color: #4a5568; font-size:1.1rem; cursor: pointer;">
            &times;
        </button>
    </div>

    <div class="location-card" id="locationCard"
        style="background:#f8f9fa; margin-bottom:25px; padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 15px;">
        <div id="locationIcon" style="font-size: 1.4rem; color: #cbd5e0;"><i class="fas fa-map-marker-alt"></i></div>
        <div style="flex: 1;">
            <div
                style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #a0aec0; font-weight: 700; margin-bottom: 4px;">
                Current Location</div>
            <div id="locationStatusText" style="font-weight: 600; color: #2d3748; line-height: 1.3; font-size:0.95rem;">
                Standby...</div>
        </div>
    </div>

    <form action="process_driver_alert.php" method="POST" id="liveReportForm">
        <input type="hidden" name="detected_location" id="detected_location" value="Unknown Location">
        <input type="hidden" name="lat" id="driver_lat">
        <input type="hidden" name="lng" id="driver_lng">
        <input type="hidden" name="alert_type" id="final_alert_type">

        <div id="step1-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
            <button type="button" class="report-btn btn-emergency"
                onclick="selectReportType('breakdown', 'Shuttle Breakdown', 'fa-tools', '#e53e3e', '#fff5f5')">
                <div class="icon-circle" style="color:#e53e3e; background:#fff5f5;"><i class="fas fa-tools"></i></div>
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span style="font-size: 0.95rem; color:#c53030; font-weight:600;">Breakdown</span>
                    <span style="font-size: 0.65rem; color:#e53e3e; font-weight:500;">Vehicle Disabled</span>
                </div>
            </button>
            <button type="button" class="report-btn btn-emergency"
                onclick="selectReportType('accident', 'Shuttle Accident', 'fa-car-crash', '#e53e3e', '#fff5f5')">
                <div class="icon-circle" style="color:#e53e3e; background:#fff5f5;"><i class="fas fa-car-crash"></i>
                </div>
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span style="font-size: 0.95rem; color:#c53030; font-weight:600;">Accident</span>
                    <span style="font-size: 0.65rem; color:#e53e3e; font-weight:500;">Shuttle Involved</span>
                </div>
            </button>
            <button type="button" class="report-btn btn-warning"
                onclick="selectReportType('traffic', 'Traffic Delay', 'fa-traffic-light', '#dd6b20', '#fffff0')">
                <div class="icon-circle" style="color:#dd6b20; background:#fffff0;"><i class="fas fa-traffic-light"></i>
                </div>
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span style="font-size: 0.95rem; color:#c05621; font-weight:600;">Heavy Traffic</span>
                    <span style="font-size: 0.65rem; color:#dd6b20; font-weight:500;">Accident Ahead/Jam</span>
                </div>
            </button>
            <button type="button" class="report-btn btn-warning"
                onclick="selectReportType('rain', 'Heavy Rain', 'fa-cloud-showers-heavy', '#3182ce', '#ebf8ff')">
                <div class="icon-circle" style="color:#3182ce; background:#ebf8ff;"><i
                        class="fas fa-cloud-showers-heavy"></i></div>
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span style="font-size: 0.95rem; color:#2b6cb0; font-weight:600;">Heavy Rain</span>
                    <span style="font-size: 0.65rem; color:#3182ce; font-weight:500;">Slow Moving</span>
                </div>
            </button>
        </div>

        <div id="step2-confirm" style="display:none; flex-direction:column; gap:20px; animation: slideUp 0.3s ease;">
            <div
                style="display:flex; align-items:center; gap:15px; padding:20px; background:#f8f9fa; border-radius:16px; border:1px solid #e2e8f0;">
                <div id="confirmIconBg"
                    style="width:55px; height:55px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.6rem;">
                    <i id="confirmIcon" class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <div
                        style="font-size:0.75rem; color:#a0aec0; text-transform:uppercase; font-weight:700; letter-spacing:0.5px;">
                        Confirm Report</div>
                    <div id="confirmTitle" style="font-size:1.3rem; font-weight:700; color:#2d3748; margin-top:2px;">
                        Category</div>
                </div>
            </div>

            <div id="confirmHelperText"
                style="display:none; font-size:0.8rem; padding:10px 12px; border-radius:8px; line-height:1.4; margin-top:-10px;">
            </div>

            <div>
                <label
                    style="font-size:0.9rem; font-weight:600; color:#4a5568; margin-bottom:8px; display:block;">Additional
                    Details (Optional)</label>
                <input type="text" name="additional_message" id="alert_details" placeholder="Provide details..."
                    style="width:100%; padding:15px; border:1px solid #cbd5e0; border-radius:12px; font-family:inherit; font-size:1rem; outline:none;">
            </div>
            <div style="display:flex; gap:12px; margin-top:10px;">
                <button type="button" onclick="cancelReport()"
                    style="flex:1; padding:18px; background:#edf2f7; color:#4a5568; border:none; border-radius:14px; font-weight:700; font-size:1.05rem; cursor:pointer;">
                    CANCEL
                </button>
                <button type="submit" id="btnSubmitReport"
                    style="flex:2; padding:18px; background:var(--primary-blue); color:white; border:none; border-radius:14px; font-weight:700; font-size:1.05rem; cursor:pointer; display:flex; justify-content:center; align-items:center; gap:10px;">
                    SEND ALERT <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>

        <div id="step3-success"
            style="display:none; flex-direction:column; align-items:center; gap:15px; padding:30px 0; animation: slideUp 0.3s ease;">
            <div
                style="width: 70px; height: 70px; background: #e8f8f5; color: #27ae60; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem;">
                <i class="fas fa-check"></i>
            </div>
            <h3 style="color: #2d3748; font-weight: 700; margin:0; font-size:1.4rem;">Alert Sent!</h3>
            <p style="color: #718096; font-size: 0.95rem; margin:0;">Operations center has been notified.</p>
        </div>
    </form>
</div>

<?php include 'components/preview_modal.php'; ?>

<?php
$extraScripts = '<script>
        const driverAssignedShuttle = ' . json_encode($shuttleId) . ';
        const driverIsOnline = ' . ($isOnline ? 'true' : 'false') . ';

        function acceptOnDemandJob(bookingId, btn) {
            btn.style.opacity = "0.7";
            btn.innerHTML = \'<i class="fas fa-circle-notch fa-spin"></i> Securing Match...\';
            const formData = new FormData(); formData.append("booking_id", bookingId);
            fetch("accept_booking.php", { method: "POST", body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) { window.location.reload(); }
                    else { alert("Failed: " + (data.message || "Error")); btn.style.opacity = "1"; btn.innerHTML = \'ACCEPT JOB <i class="fas fa-check-circle"></i>\'; }
                }).catch(err => { alert("Network Error"); btn.style.opacity = "1"; btn.innerHTML = \'ACCEPT JOB <i class="fas fa-check-circle"></i>\'; });
        }

        function rejectOnDemandJob(bookingId, btn = null, isAutoTimeout = false) {
            if (btn) {
                btn.style.opacity = "0.7";
                btn.innerHTML = \'<i class="fas fa-spinner fa-spin"></i>\';
            }
            const formData = new FormData(); 
            formData.append("booking_id", bookingId);
            fetch("reject_booking.php", { method: "POST", body: formData })
                .then(res => res.json())
                .then(data => {
                    if (!data.success && btn) {
                        alert("Failed to reject: " + (data.message || "Error"));
                        btn.style.opacity = "1"; 
                        btn.innerHTML = "DECLINE";
                    }
                }).catch(err => { 
                    console.error(err);
                });
        }

        document.addEventListener("DOMContentLoaded", function () {
            const db = firebase.firestore();
            const driverId = "' . $driverId . '";
            const sessionStartTime = Date.now();

            db.collection("Notifications").where("user_id", "==", driverId)
                .onSnapshot((snapshot) => {
                    snapshot.docChanges().forEach((change) => {
                        if (change.type !== "added") return;
                        const data = change.doc.data();
                        const notifTime = new Date((data.created_at || "").replace(" ", "T")).getTime();
                        if (notifTime > sessionStartTime) {
                            firePushNotification("CampusPulse Notification", data.message || data.title || "New update available");
                        }
                    });
                });

            let pingTimerInterval;

            db.collection("Bookings")
                .where("candidate_driver_id", "==", driverId)
                .where("status", "==", "searching")
                .onSnapshot((snapshot) => {
                    const pingContainer = document.getElementById("pinging-card-container");
                    const scanningCard = document.getElementById("scanning-card");
                    if (!pingContainer) return;

                    let hasPing = false;

                    snapshot.docChanges().forEach((change) => {
                        if (change.type === "added" || change.type === "modified") {
                            const data = change.doc.data();
                            const bookingId = change.doc.id;
                            hasPing = true;

                            if (pingTimerInterval) clearInterval(pingTimerInterval);

                            pingContainer.innerHTML = `
                                <div class="app-card job-card pinging">
                                    <div class="job-header pinging-bg">
                                        <div class="icon-box-large green"><i class="fas fa-bell"></i></div>
                                        <div style="flex:1;">
                                            <h4 class="job-title" style="color:#27ae60;">NEW RIDE REQUEST</h4>
                                            <p class="job-subtitle">Nearest Passenger Match</p>
                                        </div>
                                        <div style="font-size:1.5rem; font-weight:700; color:#e74c3c;" id="ping-timer-${bookingId}">30s</div>
                                    </div>
                                    <div class="route-path">
                                        <div class="stop-point">
                                            <div class="stop-dot dot-start"></div>
                                            <div class="stop-info" id="ping-pickup-${bookingId}">
                                                <div class="stop-label">Pick Up</div>
                                                <div class="stop-name">Fetching Location...</div>
                                            </div>
                                        </div>
                                        <div class="stop-point">
                                            <div class="stop-dot dot-end"></div>
                                            <div class="stop-info" id="ping-dropoff-${bookingId}">
                                                <div class="stop-label">Drop Off</div>
                                                <div class="stop-name">Fetching Location...</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="action-area" style="display:flex; gap:10px;">
                                        <button onclick="rejectOnDemandJob(\'${bookingId}\', this)" class="btn-massive dark" style="flex:1; font-size:1rem;">
                                            DECLINE
                                        </button>
                                        <button onclick="acceptOnDemandJob(\'${bookingId}\', this)" class="btn-massive green" style="flex:2; font-size:1rem;">
                                            ACCEPT <i class="fas fa-check-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            `;

                            if (data.pickup_stop_id) {
                                db.collection("Stops").doc(data.pickup_stop_id).get().then(doc => {
                                    const el = document.querySelector(`#ping-pickup-${bookingId} .stop-name`);
                                    if (doc.exists && el) el.innerText = doc.data().name || doc.id;
                                });
                            }
                            if (data.dropoff_stop_id) {
                                db.collection("Stops").doc(data.dropoff_stop_id).get().then(doc => {
                                    const el = document.querySelector(`#ping-dropoff-${bookingId} .stop-name`);
                                    if (doc.exists && el) el.innerText = doc.data().name || doc.id;
                                });
                            }

                            if (change.type === "added") {
                                const audio = new Audio("https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3");
                                audio.play().catch(e => { });
                            }

                            let timeLeft = 30;
                            const timerElement = document.getElementById(`ping-timer-${bookingId}`);
                            pingTimerInterval = setInterval(() => {
                                timeLeft--;
                                if (timerElement) timerElement.innerText = timeLeft + "s";
                                
                                if (timeLeft <= 0) {
                                    clearInterval(pingTimerInterval);
                                    rejectOnDemandJob(bookingId, null, true); 
                                }
                            }, 1000);
                        }
                        
                        if (change.type === "removed") {
                            pingContainer.innerHTML = "";
                            if (pingTimerInterval) clearInterval(pingTimerInterval);
                        }
                    });

                    if (hasPing && scanningCard) {
                        scanningCard.style.display = "none";
                    } else if (snapshot.empty && scanningCard) {
                        const statusText = document.getElementById("statusText");
                        if (statusText && statusText.innerText.toUpperCase() === "ONLINE") {
                            scanningCard.style.display = "block";
                        }
                        pingContainer.innerHTML = "";
                        if (pingTimerInterval) clearInterval(pingTimerInterval);
                    }
                });
        });
    </script>
    <script src="driver_dashboard.js?v=' . time() . '"></script>';
include '../layout/driver/footer.php';
?>