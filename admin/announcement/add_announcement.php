<?php
session_start();
require_once '../../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $target = $_POST['target_audience']; // 'all', 'driver', 'student'
    $schedule = !empty($_POST['schedule_time']) ? $_POST['schedule_time'] : null;

    // NEW: Capture Priority and Action Type
    $priority = $_POST['priority'] ?? 'info';
    $action = $_POST['action'] ?? 'publish';

    if (empty($title) || empty($message)) {
        $error = "Title and message are required.";
    } else {
        // NEW STATUS LOGIC
        if ($action === 'draft') {
            $status = 'draft';
        } else {
            // If schedule time is in future -> 'scheduled', else 'sent'
            $status = ($schedule && strtotime($schedule) > time()) ? 'scheduled' : 'sent';
        }

        $firestore->database()->collection('Announcements')->add([
            'title' => $title,
            'message' => $message,
            'target_audience' => $target,
            'priority' => $priority, // Save priority level
            'schedule_time' => $schedule,
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Custom success message based on action
        $msg = ($status === 'draft') ? 'draft_saved' : 'added';
        header("Location: announcements_management.php?msg=" . $msg);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>New Announcement - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>

<body>

    <div class="wrapper">
        <?php include '../../layout/sidebar.php'; ?>

        <div id="content">
            <?php include '../../layout/header.php'; ?>

            <div class="main-content">
                <div class="card" style="max-width: 750px; margin: 0 auto;">
                    <h2 style="color:var(--primary-blue); margin-bottom: 20px;">Create Announcement</h2>

                    <?php if ($error): ?>
                        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST">

                        <div style="margin-bottom:15px;">
                            <label style="font-weight:600;">Subject / Title</label>
                            <input type="text" name="title" class="form-control"
                                placeholder="e.g. Shuttle Service Interruption" required>
                        </div>

                        <div style="margin-bottom:15px;">
                            <label style="font-weight:600;">Message Content</label>
                            <textarea name="message" class="form-control" rows="6"
                                placeholder="Type your announcement here..." required></textarea>
                        </div>

                        <div
                            style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px; margin-bottom:20px; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #eee;">
                            <div>
                                <label style="font-weight:600; font-size: 0.9rem;">Target Audience</label>
                                <select name="target_audience" class="form-control">
                                    <option value="all">📢 Everyone (All Users)</option>
                                    <option value="driver">🚌 Drivers Only</option>
                                    <option value="student">🎓 Students Only</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-weight:600; font-size: 0.9rem;">Priority Level</label>
                                <select name="priority" class="form-control">
                                    <option value="info">🔵 Info (Standard)</option>
                                    <option value="warning">🟡 Warning (Important)</option>
                                    <option value="emergency">🔴 Emergency (Critical)</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-weight:600; font-size: 0.9rem;">Schedule Post</label>
                                <input type="datetime-local" name="schedule_time" class="form-control">
                            </div>
                        </div>

                        <div
                            style="display:flex; justify-content:space-between; margin-top:30px; align-items: center; border-top: 1px solid #eee; padding-top: 20px;">
                            <a href="announcements_management.php" class="btn"
                                style="background:#eee; color:#333;">Cancel</a>

                            <div style="display:flex; gap: 10px;">
                                <button type="submit" name="action" value="draft" class="btn"
                                    style="background:#6c757d; color:white;">
                                    <i class="fas fa-save"></i> Save Draft
                                </button>

                                <button type="submit" name="action" value="publish" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Publish / Schedule
                                </button>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</body>

</html>