<?php
session_start();
require_once '../../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// ANTI-DEADLOCK SHIELD
session_write_close(); 

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$serverMinutes = (intval(date('H')) * 60) + intval(date('i')); 
$db = $firestore->database();

// ===================================================================================
// 1. BULLETPROOF FETCH: SMART REPLACEMENT CANDIDATES 
// ===================================================================================
$replacementCandidates = [];
try {
    $onlineShuttles = [];
    $shuttlesQuery = $db->collection('Shuttles')->where('is_online', '=', true)->documents();
    foreach ($shuttlesQuery as $sDoc) {
        if ($sDoc->exists()) {
            $onlineShuttles[$sDoc->id()] = $sDoc->data();
        }
    }

    $driverMap = []; 
    $staffs = $db->collection('Staffs')->where('role', '=', 'driver')->documents();
    foreach ($staffs as $stf) {
        if ($stf->exists()) {
            $sId = $stf->data()['assigned_shuttle_id'] ?? '';
            if (!empty($sId)) {
                $driverMap[$sId] = [
                    'id' => $stf->id(),
                    'name' => $stf->data()['full_name'] ?? 'Unknown'
                ];
            }
        }
    }

    $todaySchedules = [];
    $allSchedules = $db->collection('Schedules')->where('date', '=', $today)->documents();
    foreach ($allSchedules as $sch) {
        if ($sch->exists()) {
            $data = $sch->data();
            $status = $data['status'] ?? '';
            $dId = $data['driver_id'] ?? '';
            
            if (in_array($status, ['published', 'scheduled', 'active'])) {
                if (!isset($todaySchedules[$dId])) {
                    $todaySchedules[$dId] = [];
                }
                $todaySchedules[$dId][] = $data['departure_time'];
            }
        }
    }

    foreach ($onlineShuttles as $shuttleId => $sData) {
        if (isset($driverMap[$shuttleId])) {
            $dId = $driverMap[$shuttleId]['id'];
            $dName = $driverMap[$shuttleId]['name'];
            $busyTimes = $todaySchedules[$dId] ?? [];
            
            $replacementCandidates[] = [
                'shuttle_id' => $shuttleId,
                'driver_id' => $dId,
                'driver_name' => $dName,
                'model' => $sData['model'] ?? 'Available',
                'current_lat' => $sData['current_lat'] ?? null,
                'current_lng' => $sData['current_lng'] ?? null,
                'is_completely_free' => empty($busyTimes),
                'busy_times' => $busyTimes
            ];
        }
    }
} catch (Exception $e) { 
    error_log("Failed to fetch candidates: " . $e->getMessage()); 
}

// ==========================================
// 2. HANDLE NEW ANNOUNCEMENT SUBMISSION 
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish') {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $additional_message = trim($_POST['additional_message'] ?? ''); 
    $tag = $_POST['tag']; 
    $target_audience = $_POST['target_audience'] ?? 'all';
    $lifespan = (int)$_POST['lifespan']; 
    $schedule_time = !empty($_POST['schedule_time']) ? $_POST['schedule_time'] : null;
    $status = ($schedule_time && strtotime($schedule_time) > strtotime($now)) ? 'scheduled' : 'active';
    $expires_at = null;
    
    if ($lifespan > 0) {
        $baseTime = $schedule_time ? strtotime($schedule_time) : strtotime($now);
        $expires_at = date('Y-m-d H:i:s', strtotime("+$lifespan hours", $baseTime));
    }
    
    $db->collection('Announcements')->add([
        'title' => $title, 
        'message' => $message, 
        'additional_message' => $additional_message, 
        'tag' => $tag, 
        'target_audience' => $target_audience,
        'author_id' => $_SESSION['user_id'] ?? 'Admin', 
        'created_at' => $now, 
        'schedule_time' => $schedule_time,
        'expires_at' => $expires_at, 
        'status' => $status
    ]);
    
    if ($status === 'active' && $target_audience !== 'public') { 
        try {
            $topic = ($target_audience === 'all') ? 'all' : $target_audience;
            $pushBody = $message;
            if (!empty($additional_message)) {
                $pushBody .= "\n\nDetails: " . $additional_message;
            }
            $notification = \Kreait\Firebase\Messaging\Notification::create($title, $pushBody);
            $cloudMessage = \Kreait\Firebase\Messaging\CloudMessage::withTarget('topic', $topic)->withNotification($notification);
            $messaging->send($cloudMessage);
        } catch (Exception $e) {}
    }
    header("Location: announcements_management.php?msg=" . ($status === 'scheduled' ? 'scheduled' : 'published'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_id'])) {
    $db->collection('Announcements')->document($_POST['revoke_id'])->update([['path' => 'status', 'value' => 'revoked']]);
    header("Location: announcements_management.php?msg=revoked");
    exit();
}

// ===================================================================================
// 3. HANDLE SHUTTLE REPLACEMENT & STRICT HANDOFF
// ===================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'replace_shuttle') {
    $alert_id = $_POST['alert_id'];
    $new_shuttle_id = trim($_POST['new_shuttle_plate']);
    $schedule_id = trim($_POST['schedule_id'] ?? ''); 
    $replacement_notes = "Emergency Replacement [".$now."]: Shuttle replaced with vehicle " . $new_shuttle_id . ".";

    // A. Fetch Alert Data
    $alertSnap = $db->collection('Announcements')->document($alert_id)->snapshot();
    $aData = $alertSnap->exists() ? $alertSnap->data() : [];
    $old_driver_id = $aData['author_id'] ?? '';
    $rescueLat = $aData['location_lat'] ?? 'N/A';
    $rescueLng = $aData['location_lng'] ?? 'N/A';
    $oldShuttle = $aData['shuttle_id'] ?? 'Unknown';

    // B. Safely Fetch New Driver Data
    $new_driver_id = null;
    $new_driver_token = null;
    $newDriverName = 'Replacement Driver';
    
    $staffs = $db->collection('Staffs')->where('role', '=', 'driver')->documents();
    foreach($staffs as $stf) {
        if ($stf->exists() && ($stf->data()['assigned_shuttle_id'] ?? '') === $new_shuttle_id) {
            $new_driver_id = $stf->id();
            $newDriverName = $stf->data()['full_name'] ?? 'Replacement Driver';
            $new_driver_token = $stf->data()['fcm_token'] ?? null;
            break;
        }
    }

    if (empty($new_driver_id)) {
        header("Location: announcements_management.php?msg=error_no_driver");
        exit();
    }

    // C. Search for Schedule if not passed explicitly
    if (empty($schedule_id) && !empty($old_driver_id)) {
        $orphanSchedules = $db->collection('Schedules')->where('driver_id', '=', $old_driver_id)->documents();
        foreach ($orphanSchedules as $sch) {
            if ($sch->exists()) {
                $sData = $sch->data();
                $status = $sData['status'] ?? '';
                $date = $sData['date'] ?? '';
                
                if ($date === $today && in_array($status, ['published', 'scheduled', 'active'])) {
                    $schedule_id = $sch->id();
                    break;
                }
            }
        }
    }

    if (empty($schedule_id)) {
        header("Location: announcements_management.php?msg=error_no_schedule");
        exit();
    }

    // Fetch the current status of the schedule before transferring
    $current_schedule_status = 'active'; 
    try {
        $schSnapToTransfer = $db->collection('Schedules')->document($schedule_id)->snapshot();
        if ($schSnapToTransfer->exists()) {
            $current_schedule_status = $schSnapToTransfer->data()['status'] ?? 'active';
        }
    } catch (Exception $e) {}

    // D. EXECUTE THE TRANSFER
    try {
        $db->collection('Schedules')->document($schedule_id)->update([
            ['path' => 'shuttle_id', 'value' => $new_shuttle_id], 
            ['path' => 'driver_id', 'value' => $new_driver_id], 
            ['path' => 'driver_name', 'value' => $newDriverName], 
            ['path' => 'rescue_lat', 'value' => $rescueLat],
            ['path' => 'rescue_lng', 'value' => $rescueLng],
            ['path' => 'notes', 'value' => $replacement_notes],
            ['path' => 'status', 'value' => $current_schedule_status]
        ]);

        $bookings = $db->collection('Bookings')->where('schedule_id', '=', $schedule_id)->documents();
        foreach ($bookings as $b) {
            if ($b->exists()) {
                $db->collection('Bookings')->document($b->id())->update([
                    ['path' => 'driver_id', 'value' => $new_driver_id],
                    ['path' => 'shuttle_id', 'value' => $new_shuttle_id]
                ]);
            }
        }
        
        $db->collection('Announcements')->document($alert_id)->update([
            ['path' => 'status', 'value' => 'resolved'],
            ['path' => 'resolved_at', 'value' => $now],
            ['path' => 'resolution_notes', 'value' => "Replaced with " . $new_shuttle_id]
        ]);

    } catch (Exception $e) {
        header("Location: announcements_management.php?msg=error_transfer_failed");
        exit();
    }

    // Send Notifications
    $notifTitle = "🚍 Shuttle Replacement Notice";
    $driverMsg = "🚨 EMERGENCY DISPATCH: You are the replacement shuttle. Navigate to the breakdown location to rescue passengers from shuttle {$oldShuttle}. Open your Active Trip to view GPS coordinates.";

    if ($new_driver_id) {
        $db->collection('Notifications')->add([
            'user_id' => $new_driver_id, 'title' => "🚨 Emergency Dispatch", 'message' => $driverMsg,
            'type' => 'dispatch_alert', 'is_read' => false, 'created_at' => $now
        ]);
        if (!empty($new_driver_token)) {
            try {
                $dMessage = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $new_driver_token)
                    ->withNotification(\Kreait\Firebase\Messaging\Notification::create("Emergency Dispatch", $driverMsg));
                $messaging->send($dMessage);
            } catch (Exception $e) {}
        }
    }

    header("Location: announcements_management.php?msg=shuttle_replaced");
    exit();
}

// ==========================================
// 4. FETCH & PROCESS LIVE FEED DATA
// ==========================================
$activeAnnouncements = [];
$stats = ['active' => 0, 'scheduled' => 0, 'driver_reports' => 0, 'public_discovery' => 0, 'resolved' => 0];

$query = $db->collection('Announcements')->orderBy('created_at', 'DESC')->documents();

foreach ($query as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        $data['id'] = $doc->id();
        
        if ($data['status'] === 'scheduled' && !empty($data['schedule_time']) && strtotime($data['schedule_time']) <= strtotime($now)) {
            $data['status'] = 'active';
            $db->collection('Announcements')->document($doc->id())->update([['path' => 'status', 'value' => 'active']]);
        }

        if ($data['status'] === 'active' && !empty($data['expires_at']) && strtotime($data['expires_at']) <= strtotime($now)) {
            $data['status'] = 'expired';
            $db->collection('Announcements')->document($doc->id())->update([['path' => 'status', 'value' => 'expired']]);
        }

        if (($data['target_audience'] ?? '') === 'public' && ($data['status'] === 'active' || $data['status'] === 'scheduled')) {
            $stats['public_discovery']++;
        }

        if (in_array($data['status'], ['active', 'scheduled', 'resolved'])) {
            $activeAnnouncements[] = $data;
            
            if ($data['status'] === 'active') $stats['active']++;
            if ($data['status'] === 'scheduled') $stats['scheduled']++;
            if ($data['status'] === 'resolved') $stats['resolved']++;

            if (isset($data['location_lat']) && $data['location_lat'] !== 'N/A' && $data['status'] !== 'resolved') {
                $stats['driver_reports']++;
            }
        }
    }
}

$pageTitle = "Announcements Management - CampusPulse";
$depth = "../../";
include $depth . 'layout/admin/header.php';
?>

    <style>
        .stat-card { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.02); 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            border-left: 4px solid var(--primary-blue); 
            cursor: pointer; 
            transition: 0.2s; 
            opacity: 1; 
            user-select: none; 
            height: 100%; 
            min-height: 100px; 
            box-sizing: border-box;
        }
        .stat-card > div:last-child {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.05); }
        .stat-card.inactive-filter { opacity: 0.4; }
        .stat-card h3 { margin: 0 0 2px 0; font-size: 1.5rem; color: #333; line-height: 1; }
        .stat-card p { margin: 0; font-size: 0.85rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.2; word-wrap: break-word; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; opacity: 0; visibility: hidden; transition: 0.3s; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .slide-modal { position: fixed; top: 0; right: -550px; width: 100%; max-width: 500px; height: 100vh; background: white; z-index: 1001; box-shadow: -5px 0 25px rgba(0,0,0,0.1); transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1); padding: 30px; overflow-y: auto; }
        .slide-modal.active { right: 0; }
        
        .center-modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); width: 100%; max-width: 400px; background: white; z-index: 1002; border-radius: 12px; padding: 25px; opacity: 0; visibility: hidden; transition: 0.3s; }
        .center-modal.active { opacity: 1; visibility: visible; transform: translate(-50%, -50%) scale(1); }

        .feed-tag { font-size: 0.75rem; font-weight: 700; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; }
        .tag-emergency { background: #ffebee; color: #c62828; }
        .tag-warning { background: #fff8e1; color: #f57f17; }
        .tag-info { background: #e3f2fd; color: #1565c0; }
        
        .aud-badge { font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 12px; background: #f1f3f5; color: #495057; border: 1px solid #e9ecef; }
        .aud-public { background: #fef9e7; color: #9a7d0a; border-color: #f7dc6f; }
        
        .template-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .template-btn { background: #f8f9fa; border: 1px solid #ddd; padding: 10px; border-radius: 6px; text-align: left; cursor: pointer; transition: 0.2s; font-size: 0.85rem; color: #333; font-weight: 600; }
        .template-btn:hover { border-color: var(--primary-blue); background: #eef2f8; }
    </style>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <div style="display:flex; align-items:center; gap: 15px;">
                    <h2 class="page-title" style="margin:0;">Announcements Management</h2>
                </div>
                <button onclick="toggleModal()" class="btn btn-primary" style="padding: 10px 20px;">
                    <i class="fas fa-plus"></i> New Broadcast
                </button>
            </div>

            <?php if(isset($_GET['msg'])): ?>
                <?php 
                    $msgText = '';
                    $isError = false;
                    if ($_GET['msg'] === 'shuttle_replaced') $msgText = 'Shuttle successfully replaced and affected users notified.';
                    elseif ($_GET['msg'] === 'error_no_driver') { $msgText = 'ERROR: No driver found assigned to that replacement shuttle.'; $isError = true; }
                    elseif ($_GET['msg'] === 'error_no_schedule') { $msgText = 'ERROR: Original driver has no active/upcoming schedule to replace.'; $isError = true; }
                    elseif ($_GET['msg'] === 'error_transfer_failed') { $msgText = 'ERROR: Database transfer failed.'; $isError = true; }
                    else $msgText = 'Action completed successfully.';
                ?>
                <div style="background: <?= $isError ? '#f8d7da' : '#d4edda' ?>; color: <?= $isError ? '#721c24' : '#155724' ?>; padding:10px; border-radius:5px; margin-bottom:15px; border-left: 4px solid <?= $isError ? '#dc3545' : '#28a745' ?>;">
                    <i class="fas <?= $isError ? 'fa-exclamation-triangle' : 'fa-check-circle' ?>"></i> <?= $msgText ?>
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-bottom: 30px; align-items: stretch;">
                <div class="stat-card" id="card-active" onclick="filterFeed('active')" style="border-left-color: #27ae60;">
                    <div style="background: #e8f8f5; padding: 15px; border-radius: 50%; color: #27ae60;"><i class="fas fa-broadcast-tower fa-lg"></i></div>
                    <div><h3><?= $stats['active'] ?></h3><p>Live Now</p></div>
                </div>
                <div class="stat-card" id="card-scheduled" onclick="filterFeed('scheduled')" style="border-left-color: #3498db;">
                    <div style="background: #ebf5fb; padding: 15px; border-radius: 50%; color: #3498db;"><i class="far fa-calendar-alt fa-lg"></i></div>
                    <div><h3><?= $stats['scheduled'] ?></h3><p>Scheduled</p></div>
                </div>
                <div class="stat-card" id="card-driver_reports" onclick="filterFeed('driver_reports')" style="border-left-color: #e74c3c;">
                    <div style="background: #fdedec; padding: 15px; border-radius: 50%; color: #e74c3c;"><i class="fas fa-map-marker-alt fa-lg"></i></div>
                    <div><h3><?= $stats['driver_reports'] ?></h3><p>Active Driver Alerts</p></div>
                </div>
                <div class="stat-card" id="card-public_discovery" onclick="filterFeed('public_discovery')" style="border-left-color: #f1c40f;">
                    <div style="background: #fef9e7; padding: 15px; border-radius: 50%; color: #f1c40f;"><i class="fas fa-eye fa-lg"></i></div>
                    <div><h3><?= $stats['public_discovery'] ?></h3><p>Public Feed</p></div>
                </div>
                <div class="stat-card" id="card-resolved" onclick="filterFeed('resolved')" style="border-left-color: #95a5a6;">
                    <div style="background: #f1f3f5; padding: 15px; border-radius: 50%; color: #95a5a6;"><i class="fas fa-history fa-lg"></i></div>
                    <div><h3><?= $stats['resolved'] ?? 0 ?></h3><p>Resolved</p></div>
                </div>
            </div>

            <div class="card" style="padding: 0;">
                <div style="padding: 20px; border-bottom: 1px solid #eee;">
                    <h3 style="margin:0; font-size: 1.1rem;"><i class="fas fa-stream"></i> System Timeline</h3>
                </div>
                <table class="styled-table" style="margin:0; width:100%;">
                    <tbody>
                        <?php if (empty($activeAnnouncements)): ?>
                            <tr><td style="text-align:center; padding:30px; color:#888;">System feed is empty.</td></tr>
                        <?php else: ?>
                            <?php foreach ($activeAnnouncements as $alert): 
                                $status = $alert['status'];
                                $tag = $alert['tag'] ?? '#Info';
                                $tagClass = strpos($tag, 'Emergency') !== false ? 'tag-emergency' : (strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false ? 'tag-warning' : 'tag-info');
                                
                                $aud = $alert['target_audience'] ?? 'all';
                                $audIcon = $aud === 'driver' ? 'fa-bus' : ($aud === 'student' ? 'fa-user-graduate' : ($aud === 'public' ? 'fa-star' : 'fa-globe'));
                                $audLabel = $aud === 'driver' ? 'Drivers' : ($aud === 'student' ? 'Students' : ($aud === 'public' ? 'Public' : 'Everyone'));
                                $audClass = $aud === 'public' ? 'aud-public' : '';
                                
                                $isDriverReport = (isset($alert['location_lat']) && $alert['location_lat'] !== 'N/A') ? 'true' : 'false';
                            ?>
                            <tr class="feed-row" data-status="<?= $status ?>" data-audience="<?= $aud ?>" data-driver="<?= $isDriverReport ?>" style="<?= $status === 'scheduled' ? 'opacity: 0.8;' : '' ?>">
                                <td style="width: 60px; text-align:center; vertical-align: top; padding-top: 15px;">
                                    <?php if ($status === 'scheduled'): ?>
                                        <i class="far fa-clock" style="color: #3498db; font-size: 1.2rem;" title="Scheduled"></i>
                                    <?php elseif ($status === 'resolved'): ?>
                                        <i class="fas fa-check-circle" style="color: #27ae60; font-size: 1.2rem;" title="Resolved"></i>
                                    <?php else: ?>
                                        <i class="fas fa-check-circle" style="color: #27ae60; font-size: 1.2rem;" title="Live"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 10px;">
                                        <span class="feed-tag <?= $tagClass ?>"><?= htmlspecialchars($tag) ?></span>
                                        <span class="aud-badge <?= $audClass ?>"><i class="fas <?= $audIcon ?>"></i> <?= $audLabel ?></span>
                                        
                                        <span style="font-size: 0.75rem; color:#888;"><i class="fas fa-paper-plane"></i> Posted: <?= date('d M, h:i A', strtotime($alert['created_at'])) ?></span>
                                        
                                        <?php if($status === 'scheduled'): ?>
                                            <span style="font-size: 0.75rem; color:#3498db; font-weight:600;"><i class="far fa-calendar-check"></i> Airs: <?= date('d M, h:i A', strtotime($alert['schedule_time'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <strong style="font-size: 1.05rem; color: #333;">
                                        <?= htmlspecialchars($alert['title']) ?>
                                        <?php if (!empty($alert['shuttle_id'])): ?>
                                            <span style="font-size: 0.85rem; color: #e67e22; background: #fff3e0; padding: 2px 6px; border-radius: 4px; margin-left: 5px; font-weight: 700;">
                                                <i class="fas fa-shuttle-van"></i> <?= htmlspecialchars($alert['shuttle_id']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </strong>
                                    
                                    <p style="margin: 3px 0 0 0; color: #555; font-size: 0.95rem; line-height: 1.4;">
                                        <?= nl2br(htmlspecialchars($alert['message'])) ?>
                                    </p>
                                    
                                    <?php if (!empty($alert['additional_message'])): ?>
                                        <div style="margin-top: 8px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #cbd5e0; border-radius: 4px; font-size: 0.85rem; color: #4a5568;">
                                            <strong style="color: #2d3748;"><i class="fas fa-info-circle"></i> Additional Details:</strong><br>
                                            <span style="font-style: italic;"><?= nl2br(htmlspecialchars($alert['additional_message'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if($isDriverReport === 'true'): ?>
                                        <div style="font-size: 0.8rem; color: #e74c3c; margin-top: 8px; font-weight: 500;">
                                            <i class="fas fa-map-marker-alt"></i> Reported near: <?= htmlspecialchars($alert['location_name']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($status === 'resolved' && !empty($alert['resolution_notes'])): ?>
                                        <div style="margin-top: 8px; padding: 8px 12px; background: #e8f8f5; border-left: 3px solid #27ae60; border-radius: 4px; font-size: 0.85rem; color: #27ae60;">
                                            <i class="fas fa-check-circle"></i> <b>Resolved:</b> <?= htmlspecialchars($alert['resolution_notes']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($status === 'active' && $isDriverReport === 'true' && strpos(strtolower($tag), 'emergency') !== false): ?>
                                        <div style="margin-top: 10px;">
                                            <button onclick="openReplaceModal('<?= $alert['id'] ?>', '<?= $alert['schedule_id'] ?? '' ?>', '<?= $alert['location_lat'] ?? 0 ?>', '<?= $alert['location_lng'] ?? 0 ?>')" class="btn btn-warning btn-sm" style="background-color: #f39c12; border:none; font-weight:600;">
                                            <i class="fas fa-tools"></i> Resolve & Replace Shuttle
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-size: 0.85rem; color: #888; vertical-align: top; padding-top: 15px;">
                                    <?php if($status !== 'resolved'): ?>
                                        <div>Exp: <?= !empty($alert['expires_at']) ? date('h:i A', strtotime($alert['expires_at'])) : 'Never' ?></div>
                                        <form method="POST" onsubmit="return confirm('Kill this broadcast?');" style="margin-top:5px;">
                                            <input type="hidden" name="revoke_id" value="<?= $alert['id'] ?>">
                                            <button type="submit" style="background:none; border:none; color:#e74c3c; cursor:pointer; font-size:0.8rem;"><u>Kill Switch</u></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

<div class="modal-overlay" id="modalOverlay" onclick="toggleModal()"></div>
<div class="slide-modal" id="createModal">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom: 1px solid #eee; padding-bottom: 15px;">
        <h3 style="margin:0; color:var(--primary-blue);"><i class="fas fa-bullhorn"></i> New Broadcast</h3>
        <button onclick="toggleModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
    </div>

    <label style="font-size: 0.85rem; font-weight: 700; color:#555; display:block; margin-bottom: 10px;">Quick Templates</label>
    <div class="template-grid">
        <button type="button" onclick="applyTemplate('welcome')" class="template-btn">Public Welcome</button>
        <button type="button" onclick="applyTemplate('feature')" class="template-btn">Feature Spotlight</button>
        <button type="button" onclick="applyTemplate('traffic')" class="template-btn">Heavy Traffic</button>
        <button type="button" onclick="applyTemplate('weather')" class="template-btn">Severe Weather</button>
        <button type="button" onclick="applyTemplate('detour')" class="template-btn">Route Detour</button>
        <button type="button" onclick="applyTemplate('maintenance')" class="template-btn">App Maintenance</button>
        <button type="button" onclick="applyTemplate('holiday')" class="template-btn">Public Holiday</button>
        <button type="button" onclick="applyTemplate('clear')" class="template-btn" style="background:#fff0f0; color:#c0392b; border-color:#fadbd8;">Clear Form</button>
    </div>

    <form method="POST" action="announcements_management.php">
        <input type="hidden" name="action" value="publish">
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
            <div>
                <label style="font-weight:600; font-size: 0.9rem;">Category Tag</label>
                <select name="tag" id="tagInput" class="form-control" required>
                    <option value="#Info">#Info (Standard Notice)</option>
                    <option value="#Important">#Important (Important Notice)</option>
                    <option value="#Warning">#Warning (Delays / Detours)</option>
                    <option value="#Emergency">#Emergency (Breakdowns / Safety)</option>
                </select>
            </div>
            <div>
                <label style="font-weight:600; font-size: 0.9rem;">Audience</label>
                <select name="target_audience" id="audienceInput" class="form-control" required>
                    <option value="all">📢 Everyone (All Users)</option>
                    <option value="public">✨ Public (Discovery Feed)</option>
                    <option value="student">🎓 Students Only</option>
                    <option value="driver">🚌 Drivers Only</option>
                </select>
            </div>
        </div>

        <div style="margin-bottom:15px;">
            <label style="font-weight:600; font-size: 0.9rem;">Headline</label>
            <input type="text" name="title" id="titleInput" class="form-control" placeholder="e.g. Shuttle Service Interruption" required>
        </div>

        <div style="margin-bottom:15px;">
            <label style="font-weight:600; font-size: 0.9rem;">Primary Message Payload</label>
            <textarea name="message" id="messageInput" class="form-control" rows="3" required></textarea>
        </div>

        <div style="margin-bottom:20px;">
            <label style="font-weight:600; font-size: 0.9rem;">Additional Details (Optional)</label>
            <textarea name="additional_message" id="additionalMessageInput" class="form-control" rows="2" placeholder="Any extra instructions, workarounds, or notes..."></textarea>
        </div>

        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #eee;">
            <div style="margin-bottom: 15px;">
                <label style="font-weight:600; font-size: 0.9rem;"><i class="far fa-clock"></i> Schedule Delivery (Optional)</label>
                <input type="datetime-local" name="schedule_time" id="scheduleInput" class="form-control" style="margin-top: 5px;">
                <small style="color:#888;">Leave blank to broadcast instantly.</small>
            </div>

            <div>
                <label style="font-weight:600; font-size: 0.9rem;"><i class="fas fa-hourglass-half"></i> Auto-Expire (Lifespan)</label>
                <select name="lifespan" id="lifespanInput" class="form-control" style="margin-top: 5px;">
                    <option value="2">Remove after 2 Hours</option>
                    <option value="12">Remove after 12 Hours</option>
                    <option value="24" selected>Remove after 24 Hours</option>
                    <option value="168">Remove after 1 Week</option>
                    <option value="0">Never Expire</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 1.1rem;">
            <i class="fas fa-paper-plane"></i> Initialize Broadcast
        </button>
    </form>
</div>

<div class="center-modal" id="replaceModal">
    <h4 style="color:#e74c3c; margin-top:0;"><i class="fas fa-tools"></i> Emergency Shuttle Replacement</h4>
    <p style="font-size: 0.9rem; color:#666;">Select an available shuttle to safely replace the broken down vehicle. Busy shuttles are hidden to prevent schedule clashes.</p>
    <form method="POST">
        <input type="hidden" name="action" value="replace_shuttle">
        <input type="hidden" name="alert_id" id="replaceAlertId">
        <input type="hidden" name="schedule_id" id="replaceScheduleId"> 
        
        <label style="font-weight:600; font-size: 0.9rem;">Available Replacement Shuttles</label>
        <select name="new_shuttle_plate" id="newShuttleSelect" class="form-control" required style="margin-bottom: 15px;">
        </select>
        
        <div style="display:flex; gap:10px;">
            <button type="button" onclick="closeReplaceModal()" class="btn btn-outline-secondary" style="flex:1;">Cancel</button>
            <button type="submit" id="confirmReplaceBtn" class="btn btn-warning" style="flex:1; font-weight:600;"><i class="fas fa-check"></i> Confirm Handoff</button>
        </div>
    </form>
</div>

<script>
    function toggleModal() {
        document.getElementById('modalOverlay').classList.toggle('active');
        document.getElementById('createModal').classList.toggle('active');
        document.getElementById('replaceModal').classList.remove('active');
    }

    const replacementCandidates = <?= json_encode($replacementCandidates) ?>;
    const currentServerMinutes = <?= $serverMinutes ?>; 

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; 
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    function openReplaceModal(alertId, scheduleId, lat, lng) {
        document.getElementById('modalOverlay').classList.add('active');
        document.getElementById('replaceModal').classList.add('active');
        document.getElementById('replaceAlertId').value = alertId;
        document.getElementById('replaceScheduleId').value = scheduleId;

        const select = document.getElementById('newShuttleSelect');
        const submitBtn = document.getElementById('confirmReplaceBtn'); 
        
        select.innerHTML = '<option value="">-- Select Replacement --</option>';

        const alertLat = parseFloat(lat);
        const alertLng = parseFloat(lng);
        
        let sortedShuttles = [];

        replacementCandidates.forEach(c => {
            const sLat = parseFloat(c.current_lat || 0);
            const sLng = parseFloat(c.current_lng || 0);
            let dist = 9999;
            
            if (sLat && sLng && !isNaN(alertLat) && !isNaN(alertLng)) {
                dist = calculateDistance(alertLat, alertLng, sLat, sLng);
            }

            let isClashing = false;

            if (!c.is_completely_free && c.busy_times) {
                c.busy_times.forEach(timeStr => {
                    const parts = timeStr.split(':');
                    if(parts.length >= 2) {
                        const taskTimeInMinutes = (parseInt(parts[0]) * 60) + parseInt(parts[1]);
                        
                        if (taskTimeInMinutes >= (currentServerMinutes - 60) && taskTimeInMinutes <= (currentServerMinutes + 150)) {
                            isClashing = true;
                        }
                    }
                });
            }

            if (!isClashing) {
                sortedShuttles.push({...c, distance: dist});
            }
        });

        sortedShuttles.sort((a, b) => a.distance - b.distance);

        sortedShuttles.forEach(s => {
            const distText = s.distance === 9999 ? 'Unknown dist' : s.distance.toFixed(1) + ' km away';
            select.innerHTML += `<option value="${s.shuttle_id}">
                ${s.shuttle_id} (${s.driver_name}) - ${distText}
            </option>`;
        });

        if(sortedShuttles.length === 0) {
            select.innerHTML += `<option value="" disabled>No idle shuttles available</option>`;
            submitBtn.disabled = true;
        } else {
            submitBtn.disabled = false;
        }
    }
    
    function closeReplaceModal() {
        document.getElementById('modalOverlay').classList.remove('active');
        document.getElementById('replaceModal').classList.remove('active');
    }

    let currentActiveFilter = 'all';
    function filterFeed(type) {
        if (currentActiveFilter === type) {
            type = 'all'; 
        }
        currentActiveFilter = type;

        document.querySelectorAll('.stat-card').forEach(el => el.classList.add('inactive-filter'));
        
        if (type !== 'all') {
            document.getElementById('card-' + type).classList.remove('inactive-filter');
        } else {
            document.querySelectorAll('.stat-card').forEach(el => el.classList.remove('inactive-filter'));
        }

        const rows = document.querySelectorAll('.feed-row');
        rows.forEach(row => {
            let show = false;
            if (type === 'all') show = true;
            else if (type === 'active' && row.dataset.status === 'active') show = true;
            else if (type === 'scheduled' && row.dataset.status === 'scheduled') show = true;
            else if (type === 'driver_reports' && row.dataset.driver === 'true' && row.dataset.status !== 'resolved') show = true;
            else if (type === 'public_discovery' && row.dataset.audience === 'public') show = true;
            else if (type === 'resolved' && row.dataset.status === 'resolved') show = true;
            
            row.style.display = show ? '' : 'none';
        });
    }

    const templates = {
        'welcome': { tag: '#Info', aud: 'public', title: 'Welcome to CampusPulse: Your Campus, Reimagined.', msg: 'Welcome to UniKL\'s smart mobility hub! We are dedicated to making your campus commute seamless, safe, and efficient.', additional: 'Feel free to explore our web schedule and download the app to get started.', life: '0' },
        'feature': { tag: '#Info', aud: 'public', title: 'Fixed Schedule or On-Demand? You Choose.', msg: 'Experience the power of our Hybrid Network. Use our Scheduled Service for reliable daily timings, or switch to On-Demand mode during off-peak hours.', additional: '', life: '168' },
        'traffic': { tag: '#Warning', aud: 'all', title: 'Service Delay: Heavy Traffic', msg: 'Shuttles are currently experiencing delays of 15-20 minutes due to unexpected heavy traffic.', additional: 'Please plan your journey accordingly and expect extended wait times at the Main Gate.', life: '2' },
        'weather': { tag: '#Warning', aud: 'all', title: 'Weather Alert: Heavy Rain', msg: 'Due to severe weather conditions, shuttles will proceed at a reduced speed for safety.', additional: 'Please wait at covered shelters and keep umbrellas ready during boarding.', life: '3' },
        'detour': { tag: '#Info', aud: 'driver', title: 'Temporary Route Detour', msg: 'Drivers: Road closures ahead on Main Ave. Please bypass Stop B temporarily and proceed directly to Stop C via the North Route.', additional: '', life: '12' },
        'maintenance': { tag: '#Info', aud: 'all', title: 'Scheduled App Maintenance', msg: 'The CampusPulse system will undergo brief maintenance tonight starting at 1:00 AM.', additional: 'Live tracking will be unavailable for 30 minutes during this window.', life: '24' },
        'holiday': { tag: '#Info', aud: 'all', title: 'Public Holiday Schedule', msg: 'Please be informed that regular shuttle operations are paused for the upcoming public holiday.', additional: 'Normal service will resume the following business day at 7:00 AM.', life: '168' },
        'clear': { tag: '#Info', aud: 'all', title: '', msg: '', additional: '', life: '24' }
    };

    function applyTemplate(type) {
        if(templates[type]) {
            document.getElementById('tagInput').value = templates[type].tag;
            document.getElementById('audienceInput').value = templates[type].aud;
            document.getElementById('titleInput').value = templates[type].title;
            document.getElementById('messageInput').value = templates[type].msg;
            document.getElementById('additionalMessageInput').value = templates[type].additional; 
            document.getElementById('lifespanInput').value = templates[type].life;
            
            ['titleInput', 'messageInput', 'additionalMessageInput'].forEach(id => {
                const el = document.getElementById(id);
                el.style.backgroundColor = '#e8f8f5';
                setTimeout(() => el.style.backgroundColor = 'white', 300);
            });
        }
    }
</script>

<?php
// Inject Firebase SDK and Real-Time Listener to instantly pop up Emergency Alerts
$extraScripts = "
<script src=\"https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js\"></script>
<script src=\"https://www.gstatic.com/firebasejs/8.10.1/firebase-firestore.js\"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof firebase !== 'undefined') {
            if (!firebase.apps.length) {
                firebase.initializeApp({
                    apiKey: '" . (MAPS_API_KEY ?? '') . "',
                    authDomain: '" . (FIREBASE_AUTH_DOMAIN ?? '') . "',
                    projectId: '" . (FIREBASE_PROJECT_ID ?? '') . "'
                });
            }
            const db = firebase.firestore();
            
            // Generate exact local time string to only trigger on NEW alerts
            const tzTime = new Date(new Date().toLocaleString('en-US', {timeZone: 'Asia/Kuala_Lumpur'}));
            const nowLocalStr = tzTime.getFullYear() + '-' + 
                String(tzTime.getMonth() + 1).padStart(2, '0') + '-' + 
                String(tzTime.getDate()).padStart(2, '0') + ' ' + 
                String(tzTime.getHours()).padStart(2, '0') + ':' + 
                String(tzTime.getMinutes()).padStart(2, '0') + ':' + 
                String(tzTime.getSeconds()).padStart(2, '0');

            db.collection('Announcements')
              .where('status', 'in', ['active', 'scheduled'])
              .onSnapshot(snapshot => {
                  snapshot.docChanges().forEach(change => {
                      if (change.type === 'added') {
                          const data = change.doc.data();
                          // Ensure it's a NEW alert from a driver
                          if (data.created_at > nowLocalStr && data.location_lat && data.location_lat !== 'N/A') {
                              if (data.tag && (data.tag.includes('Emergency') || data.tag.includes('Warning'))) {
                                  alert('🚨 INCOMING DRIVER ALERT: ' + data.title + '\\n\\n' + data.message);
                                  window.location.reload();
                              }
                          }
                      }
                  });
              });
        }
    });
</script>";

include $depth . 'layout/admin/footer.php'; 
?>