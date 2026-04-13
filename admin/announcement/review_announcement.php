<?php
session_start();
require_once '../../config.php'; // Adjust path if needed based on your folder structure
date_default_timezone_set('Asia/Kuala_Lumpur');

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: announcements_management.php");
    exit();
}

// 2. Fetch the specific pending report
$docRef = $firestore->database()->collection('Announcements')->document($id);
$snapshot = $docRef->snapshot();

if (!$snapshot->exists()) {
    header("Location: announcements_management.php?msg=notfound");
    exit();
}

$data = $snapshot->data();

// Security: If it's already been processed, send them back
if (($data['status'] ?? '') !== 'pending_review') {
    header("Location: announcements_management.php");
    exit();
}

$error = "";

// 3. Handle Admin Approval Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $audience = $_POST['target_audience'] ?? 'all';
    $priority = $_POST['priority'] ?? 'warning';
    $schedule_time = !empty($_POST['schedule_time']) ? $_POST['schedule_time'] : null;
    $lifespan_hours = $_POST['lifespan'] ?? '0'; // 0 = No expiry

    try {
        $now = time();
        // Determine if it goes live now or later
        $status = ($schedule_time && strtotime($schedule_time) > $now) ? 'scheduled' : 'sent';

        // Calculate Expiry Date (if applicable)
        $expires_at = null;
        if ($lifespan_hours > 0) {
            $baseTime = $schedule_time ? strtotime($schedule_time) : $now;
            $expires_at = date('Y-m-d H:i:s', $baseTime + ($lifespan_hours * 3600));
        }

        // Update Firestore Document
        $docRef->update([
            ['path' => 'target_audience', 'value' => $audience],
            ['path' => 'priority', 'value' => $priority],
            ['path' => 'schedule_time', 'value' => $schedule_time],
            ['path' => 'expires_at', 'value' => $expires_at],
            ['path' => 'status', 'value' => $status],
            ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
        ]);

        header("Location: announcements_management.php?msg=published");
        exit();

    } catch (Exception $e) {
        $error = "Failed to update database: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Review Driver Report - CampusPulse</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        .split-layout {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .raw-data-panel {
            flex: 1;
            min-width: 300px;
            background: #fff9e6;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 20px;
        }

        .control-panel {
            flex: 1.5;
            min-width: 350px;
            background: white;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        }

        .data-label {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 3px;
            display: block;
        }

        .data-value {
            font-size: 1.05rem;
            color: #333;
            margin-bottom: 15px;
            font-weight: 500;
        }
    </style>
</head>

<body>

    <div class="wrapper">
        <?php $depth = '../../';
        include '../../layout/sidebar.php'; ?>

        <div id="content">
            <?php include '../../layout/header.php'; ?>

            <div class="main-content">
                <div style="max-width: 1000px; margin: 0 auto;">

                    <div style="display:flex; align-items:center; gap: 15px; margin-bottom: 25px;">
                        <a href="announcements_management.php" class="btn"
                            style="background:#eee; color:#333; padding: 8px 12px;"><i class="fas fa-arrow-left"></i>
                            Back</a>
                        <h2 style="color:var(--primary-blue); margin: 0;">Review Driver Report</h2>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert-error">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <div class="split-layout">

                        <div class="raw-data-panel">
                            <h3
                                style="color:#d35400; margin-top:0; border-bottom: 2px solid #ffeeba; padding-bottom: 10px; margin-bottom: 20px;">
                                <i class="fas fa-satellite-dish"></i> Incoming Report
                            </h3>

                            <span class="data-label">Suggested Title</span>
                            <div class="data-value" style="color:#c0392b; font-weight:700;">
                                <?= htmlspecialchars($data['title'] ?? 'No Title') ?>
                            </div>

                            <span class="data-label">Message Payload</span>
                            <div class="data-value"
                                style="background: white; padding: 10px; border-radius: 6px; border: 1px solid #ffeeba; font-size: 0.95rem;">
                                <?= htmlspecialchars($data['message'] ?? 'No Message') ?>
                            </div>

                            <span class="data-label">Detected Location</span>
                            <div class="data-value">
                                <i class="fas fa-map-marker-alt" style="color:#27ae60;"></i>
                                <?= htmlspecialchars($data['location_name'] ?? 'Unknown Location') ?>
                            </div>

                            <span class="data-label">GPS Coordinates</span>
                            <div class="data-value" style="font-family: monospace; font-size: 0.9rem;">
                                Lat:
                                <?= htmlspecialchars($data['location_lat'] ?? 'N/A') ?><br>
                                Lng:
                                <?= htmlspecialchars($data['location_lng'] ?? 'N/A') ?>
                            </div>

                            <span class="data-label">Reported Time</span>
                            <div class="data-value" style="font-size: 0.9rem;">
                                <?= date('d M Y, h:i A', strtotime($data['created_at'])) ?>
                            </div>
                        </div>

                        <div class="control-panel">
                            <h3
                                style="color:var(--primary-blue); margin-top:0; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px;">
                                <i class="fas fa-sliders-h"></i> Publishing Controls
                            </h3>

                            <form method="POST">
                                <div style="margin-bottom:20px;">
                                    <label style="font-weight:600;">Broadcast Audience</label>
                                    <select name="target_audience" class="form-control" required>
                                        <option value="all" <?= ($data['target_audience'] ?? '') === 'all' ? 'selected' : '' ?>>📢 Everyone (All Users)</option>
                                        <option value="driver" <?= ($data['target_audience'] ?? '') === 'driver' ? 'selected' : '' ?>>🚌 Drivers Only (Internal Warning)</option>
                                        <option value="student" <?= ($data['target_audience'] ?? '') === 'student' ? 'selected' : '' ?>>🎓 Students Only</option>
                                    </select>
                                </div>

                                <div style="margin-bottom:20px;">
                                    <label style="font-weight:600;">Priority Level</label>
                                    <select name="priority" class="form-control" required>
                                        <option value="info">🔵 Info (Standard Notice)</option>
                                        <option value="warning" selected>🟡 Warning (Important Update)</option>
                                        <option value="emergency">🔴 Emergency (Critical Alert Banner)</option>
                                    </select>
                                    <small style="color:#888; display:block; margin-top:5px;">Warning and Emergency will
                                        trigger colored banners on student dashboards.</small>
                                </div>

                                <div
                                    style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:30px; background: #f8f9fa; padding: 15px; border-radius: 8px;">
                                    <div>
                                        <label style="font-weight:600;">Schedule Post</label>
                                        <input type="datetime-local" name="schedule_time" class="form-control">
                                        <small style="color:#888;">Leave blank for immediate broadcast.</small>
                                    </div>
                                    <div>
                                        <label style="font-weight:600;">Alert Lifespan (Auto-Expire)</label>
                                        <select name="lifespan" class="form-control">
                                            <option value="1">1 Hour</option>
                                            <option value="3" selected>3 Hours (Recommended for Traffic/Rain)</option>
                                            <option value="12">12 Hours</option>
                                            <option value="24">24 Hours</option>
                                            <option value="0">Never Expire (Manual removal)</option>
                                        </select>
                                    </div>
                                </div>

                                <div
                                    style="display:flex; justify-content: flex-end; border-top: 1px solid #eee; padding-top: 20px;">
                                    <button type="submit" class="btn btn-primary"
                                        style="padding: 12px 30px; font-size: 1.1rem; background: #27ae60; border: none;">
                                        <i class="fas fa-check-double"></i> Verify & Broadcast Now
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>

                </div>
            </div>
        </div>
    </div>
</body>

</html>