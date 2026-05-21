<?php
session_start();
require_once '../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$driverId = $_SESSION['user_id'];
$now = time();
$lastReadTime = 0;

try {


    $driverRef = $firestore->collection('Staffs')->document($driverId);
    $driverSnap = $driverRef->snapshot();

    if ($driverSnap->exists()) {
        $lastReadTime = $driverSnap->data()['last_alert_read_time'] ?? 0;
    }


    $driverRef->update([
        ['path' => 'last_alert_read_time', 'value' => $now]
    ]);

} catch (Exception $e) {
    // Fail silently, just means the badge might not clear
}

$alerts = [];

try {

    $docs = $firestore->collection('Announcements')
        ->where('status', 'in', ['active', 'scheduled'])
        ->orderBy('created_at', 'DESC')
        ->documents();

    foreach ($docs as $doc) {
        $data = $doc->data();
        $audience = $data['target_audience'] ?? 'all';
        $status = $data['status'] ?? 'active';


        if ($audience !== 'driver' && $audience !== 'all')
            continue;


        if (!empty($data['expires_at']) && strtotime($data['expires_at']) <= $now)
            continue;


        $publishTime = !empty($data['schedule_time']) ? strtotime($data['schedule_time']) : strtotime($data['created_at']);
        if ($publishTime > $now)
            continue;

        if ($status === 'revoked')
            continue;


        $data['id'] = $doc->id();


        $data['is_new'] = ($publishTime > $lastReadTime);


        $diff = $now - $publishTime;
        if ($diff < 60) {
            $data['relative_time'] = 'Just now';
        } elseif ($diff < 3600) {
            $data['relative_time'] = floor($diff / 60) . ' mins ago';
        } elseif ($diff < 86400) {
            $data['relative_time'] = floor($diff / 3600) . ' hours ago';
        } else {
            $data['relative_time'] = date('d M', $publishTime);
        }

        $alerts[] = $data;
    }
} catch (Exception $e) {
    // If announcements fail, just continue with empty array
}

try {
    $notificationsDocs = $firestore->collection('Notifications')
        ->where('user_id', '=', $driverId)
        ->documents();

    foreach ($notificationsDocs as $doc) {
        $data = $doc->data();
        $data['id'] = $doc->id();
        $isRead = $data['is_read'] ?? true;
        $data['is_new'] = !$isRead;

        $publishTime = strtotime($data['created_at'] ?? 'now');
        $diff = $now - $publishTime;
        if ($diff < 60) {
            $data['relative_time'] = 'Just now';
        } elseif ($diff < 3600) {
            $data['relative_time'] = floor($diff / 60) . ' mins ago';
        } elseif ($diff < 86400) {
            $data['relative_time'] = floor($diff / 3600) . ' hours ago';
        } else {
            $data['relative_time'] = date('d M', $publishTime);
        }

        $alerts[] = $data;

        // Mark as read immediately
        if ($isRead === false) {
            $firestore->collection('Notifications')->document($doc->id())->update([
                ['path' => 'is_read', 'value' => true]
            ]);
        }
    }
} catch (Exception $e) {
    // Fail silently for personal notifications too
}


try {
    $driverData = $firestore->collection('Staffs')->document($driverId)->snapshot()->data();
    $todayDate = new DateTime('today');
    $licExp = $driverData['license_expiry'] ?? '';
    $psvExp = $driverData['psv_expiry'] ?? '';
    $licDays = !empty($licExp) ? (int) $todayDate->diff(new DateTime($licExp))->format('%r%a') : null;
    $psvDays = !empty($psvExp) ? (int) $todayDate->diff(new DateTime($psvExp))->format('%r%a') : null;

    if ($licDays !== null && $licDays >= 0 && $licDays <= 30) {
        $alerts[] = [
            'id' => 'lic_warn_' . $now,
            'title' => 'License Expiring Soon',
            'message' => "Your driver license expires in $licDays days. Please upload renewed documents in your Profile soon.",
            'tag' => '#Warning',
            'is_new' => true,
            'timestamp' => $now,
            'relative_time' => 'System Alert'
        ];
    }
    if ($psvDays !== null && $psvDays >= 0 && $psvDays <= 30) {
        $alerts[] = [
            'id' => 'psv_warn_' . $now,
            'title' => 'PSV Expiring Soon',
            'message' => "Your PSV license expires in $psvDays days. Please upload renewed documents in your Profile soon.",
            'tag' => '#Warning',
            'is_new' => true,
            'timestamp' => $now,
            'relative_time' => 'System Alert'
        ];
    }
} catch (Exception $e) {
}


foreach ($alerts as &$a) {
    if (!isset($a['timestamp'])) {
        $a['timestamp'] = !empty($a['schedule_time']) ? strtotime($a['schedule_time']) : strtotime($a['created_at'] ?? 'now');
    }
}
unset($a);

usort($alerts, function ($a, $b) {
    return $b['timestamp'] <=> $a['timestamp'];
});

$pageTitle = 'Driver Notifications';
$extraHead = '
<style>
    body {
        background-color: #f8f9fc;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    .alerts-header {
        background: var(--primary-blue);
        color: white;
        padding: 30px 20px 80px 20px;
        border-bottom-left-radius: 35px;
        border-bottom-right-radius: 35px;
        position: relative;
        z-index: 1;
    }

    .alerts-container {
        margin-top: -50px;
        padding: 0 20px 100px 20px;
        position: relative;
        z-index: 2;
        flex: 1;
    }

    .alert-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.04);
        border-left: 6px solid #ddd;
        transition: all 0.3s ease;
    }

    .alert-card.is-new {
        background-color: #f0f8ff;
        box-shadow: 0 8px 25px rgba(52, 152, 219, 0.15);
    }

    .badge-new {
        color: var(--primary-blue);
        font-weight: 700;
        font-size: 0.7rem;
        margin-left: 5px;
        background: rgba(52, 152, 219, 0.15);
        padding: 2px 6px;
        border-radius: 4px;
    }

    .alert-emergency { border-left-color: #e74c3c; }
    .alert-emergency .icon-circle { background: #fdedec; color: #e74c3c; }
    .alert-warning { border-left-color: #f1c40f; }
    .alert-warning .icon-circle { background: #fef9e7; color: #f39c12; }
    .alert-info { border-left-color: #3498db; }
    .alert-info .icon-circle { background: #ebf5fb; color: #3498db; }

    .icon-circle {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        margin-right: 15px;
        flex-shrink: 0;
    }

    .alert-title { font-size: 1rem; font-weight: 700; color: #333; line-height: 1.2; }
    .alert-time { font-size: 0.7rem; color: #bbb; text-transform: uppercase; font-weight: 600; display: flex; align-items: center; }
    .alert-body { font-size: 0.9rem; color: #666; line-height: 1.5; margin-top: 10px; }

    .location-badge {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #eee;
        font-size: 0.8rem;
        color: #e74c3c;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .empty-state {
        text-align: center;
        padding: 80px 20px;
        color: #ccc;
        background: white;
        border-radius: 20px;
    }
</style>';
include '../layout/driver/header.php';
?>

    <div class="alerts-header">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h2 style="margin:0; font-size:1.5rem; font-weight:700;">System Feed</h2>
                <p style="margin:2px 0 0 0; opacity:0.8; font-size:0.85rem;">Live traffic & admin updates</p>
            </div>
            <div
                style="background:rgba(255,255,255,0.15); width:45px; height:45px; border-radius:15px; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                <i class="fas fa-rss"></i>
            </div>
        </div>
    </div>

    <div class="alerts-container">

        <?php if (empty($alerts)): ?>
            <div class="empty-state">
                <div style="font-size: 4rem; margin-bottom: 20px; opacity: 0.3;"><i class="fas fa-check-circle"></i></div>
                <h3 style="margin:0; color:#555;">All Systems Clear</h3>
                <p style="margin:5px 0 0 0; font-size:0.9rem;">No active alerts or delays reported.</p>
            </div>
        <?php else: ?>

            <?php foreach ($alerts as $alert):
                $tag = $alert['tag'] ?? '#Info';
                $cardStyle = 'alert-info';
                $icon = 'fa-info-circle';
                $iconClassEx = '';

                $newClass = !empty($alert['is_new']) ? 'is-new' : '';

                if (strpos($tag, 'Emergency') !== false) {
                    $cardStyle = 'alert-emergency';
                    $icon = 'fa-exclamation-circle';
                } elseif (strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false) {
                    $cardStyle = 'alert-warning';
                    $icon = 'fa-triangle-exclamation';
                } elseif (strpos($tag, 'Account') !== false) {
                    $cardStyle = 'alert-info';
                    $icon = 'fa-check-circle';
                    $iconClassEx = 'style="color:#27ae60"';
                }
                ?>
                <div class="alert-card <?= $cardStyle ?> <?= $newClass ?>">
                    <div style="display:flex; align-items:center;">
                        <div class="icon-circle" <?= $iconClassEx ?>><i class="fas <?= $icon ?>"></i></div>
                        <div style="flex:1;">
                            <div class="alert-time">
                                <?= $alert['relative_time'] ?> • <?= htmlspecialchars($tag) ?>
                                <?php if (!empty($alert['is_new'])): ?>
                                    <span class="badge-new">NEW</span>
                                <?php endif; ?>
                            </div>
                            <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
                        </div>
                    </div>

                    <div class="alert-body">
                        <?= nl2br(htmlspecialchars($alert['message'])) ?>
                    </div>

                    <!-- NEW: DISPLAY ADDITIONAL MESSAGE FOR DRIVERS -->
                    <?php if (!empty($alert['additional_message'])): ?>
                        <div
                            style="margin-top: 10px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #cbd5e0; border-radius: 4px; font-size: 0.85rem; color: #4a5568;">
                            <strong style="color: #2d3748;"><i class="fas fa-info-circle"></i> Details:</strong><br>
                            <span style="font-style: italic;"><?= nl2br(htmlspecialchars($alert['additional_message'])) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($alert['location_name'])): ?>
                        <div class="location-badge">
                            <i class="fas fa-map-marker-alt"></i> Near: <?= htmlspecialchars($alert['location_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

<?php include '../layout/driver/footer.php'; ?>