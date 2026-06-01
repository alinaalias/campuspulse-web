<?php
require_once 'config.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// Detect login & role
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;

// ==========================================
// 1. THE GATEKEEPER (No Limits)
// ==========================================
$now = date('Y-m-d H:i:s');
$nowTimestamp = strtotime($now);

$activeAnnouncements = [];

try {
    $announcementsRef = $firestore
        ->collection('Announcements')
        ->where('status', 'in', ['active', 'scheduled'])
        ->documents();

    foreach ($announcementsRef as $doc) {
        $data = $doc->data();

        $target = $data['target_audience'] ?? 'all';
        if ($target === 'driver')
            continue;

        if (isset($data['status']) && $data['status'] === 'revoked')
            continue;

        if (!empty($data['expires_at']) && strtotime($data['expires_at']) <= $nowTimestamp)
            continue;

        $isVisible = false;
        if ($data['status'] === 'active') {
            $isVisible = true;
        } elseif ($data['status'] === 'scheduled' && !empty($data['schedule_time'])) {
            if (strtotime($data['schedule_time']) <= $nowTimestamp) {
                $isVisible = true;
            }
        }

        if ($isVisible) {
            $data['sort_time'] = !empty($data['schedule_time']) ? strtotime($data['schedule_time']) : strtotime($data['created_at']);
            $activeAnnouncements[] = $data;
        }
    }
} catch (Exception $e) {
    // Fail silently
}

// Sort Newest First
usort($activeAnnouncements, function ($a, $b) {
    return $b['sort_time'] - $a['sort_time'];
});
?>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }

        /* Clean Header */
        .page-header {
            background: linear-gradient(135deg, #003366 0%, #004080 100%);
            color: white;
            padding: 60px 20px;
            text-align: center;
            margin-bottom: 40px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 1.1rem;
        }

        /* Grid System matching the homepage */
        .announcement-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 80px 20px;
        }

        .announcement-card {
            background: white;
            border: 1px solid #eee;
            border-radius: 12px;
            padding: 25px;
            border-left: 5px solid var(--primary-blue);
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }

        .announcement-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            transform: translateY(-2px);
        }

        .feed-tag {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 12px;
        }

        .tag-emergency {
            background: #ffebee;
            color: #c62828;
        }

        .tag-warning {
            background: #fff8e1;
            color: #f57f17;
        }

        .tag-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        .card-emergency {
            border-left-color: #c62828;
        }

        .card-warning {
            border-left-color: #f57f17;
        }

        .card-info {
            border-left-color: #1565c0;
        }
    </style>


    <?php include 'layout/public/header.php'; ?>

    <div class="page-header">
        <h1><i class="fas fa-broadcast-tower"></i> Campus Updates</h1>
        <p>All active service announcements, alerts, and schedules.</p>
    </div>

    <?php if (empty($activeAnnouncements)): ?>
        <div
            style="text-align:center; padding:60px 20px; max-width: 600px; margin: 0 auto 80px auto; background:white; border-radius:16px; border: 1px solid #eee;">
            <i class="fas fa-check-circle" style="font-size: 4rem; color: #27ae60; margin-bottom: 20px;"></i>
            <h3 style="color: #333; margin: 0 0 10px 0;">All Systems Normal</h3>
            <p style="color:#777; margin: 0;">There are no active service delays, detours, or campus announcements at this
                time.</p>
            <a href="index.php" class="btn btn-primary" style="margin-top: 20px; display: inline-block;">Return Home</a>
        </div>
    <?php else: ?>
        <div class="announcement-grid">
            <?php foreach ($activeAnnouncements as $announcement):
                $tag = $announcement['tag'] ?? '#Info';
                $isEmergency = strpos($tag, 'Emergency') !== false;
                $isWarning = strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false;

                $tagClass = $isEmergency ? 'tag-emergency' : ($isWarning ? 'tag-warning' : 'tag-info');
                $cardClass = $isEmergency ? 'card-emergency' : ($isWarning ? 'card-warning' : 'card-info');
                ?>
                <div class="announcement-card <?= $cardClass ?>">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <span class="feed-tag <?= $tagClass ?>">
                            <?= htmlspecialchars($tag) ?>
                        </span>
                        <small style="color:#999; font-size:0.8rem; font-weight: 500;">
                            <i class="far fa-clock"></i>
                            <?= date('d M Y, h:i A', $announcement['sort_time']) ?>
                        </small>
                    </div>

                    <h3 style="margin: 0 0 8px 0; font-size:1.1rem; color:#333; font-weight:700;">
                        <?= htmlspecialchars($announcement['title']) ?>
                    </h3>

                    <p style="color:#555; line-height:1.6; margin:0; font-size:0.95rem; flex-grow: 1;">
                        <?= nl2br(htmlspecialchars($announcement['message'])) ?>
                    </p>

                    <?php if (!empty($announcement['location_name'])): ?>
                        <div
                            style="margin-top: 15px; padding-top: 12px; border-top: 1px dashed #eee; font-size: 0.8rem; color: #e74c3c; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($announcement['location_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php include 'layout/public/footer.php'; ?>
