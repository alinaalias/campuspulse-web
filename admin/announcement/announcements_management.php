<?php
session_start();
require_once '../../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// ==========================================
// 1. AUTO-PUBLISH LOGIC (The Fix)
// ==========================================
// Check for any announcements that are 'pending' but their schedule_time has passed
$now = date('Y-m-d H:i:s');
$pendingQuery = $firestore->database()->collection('Announcements')
    ->where('status', '=', 'pending')
    ->where('schedule_time', '<=', $now)
    ->documents();

if (!$pendingQuery->isEmpty()) {
    $batch = $firestore->database()->batch();
    $updatesCount = 0;

    foreach ($pendingQuery as $doc) {
        // Ensure schedule_time is actually set and valid before updating
        $data = $doc->data();
        if (!empty($data['schedule_time'])) {
            $batch->update($doc->reference(), [
                ['path' => 'status', 'value' => 'sent']
            ]);
            $updatesCount++;
        }
    }

    // Commit batch update if there were changes
    if ($updatesCount > 0) {
        $batch->commit();
    }
}
// ==========================================

// 2. DELETE LOGIC (Handle Deletion Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $firestore->database()->collection('Announcements')->document($_POST['delete_id'])->delete();
    header("Location: announcements_management.php?msg=deleted");
    exit();
}

// 3. FETCH ANNOUNCEMENTS (Sorted Newest First)
$announcements = [];
$query = $firestore->database()->collection('Announcements')->orderBy('created_at', 'DESC')->documents();
foreach ($query as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        $data['id'] = $doc->id(); // Capture ID
        $announcements[] = $data;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Announcements - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; display: inline-flex; align-items: center; gap: 5px; }
        
        /* Audience Badges */
        .badge-all     { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .badge-driver  { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .badge-student { background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 class="page-title">Announcements</h2>
                <a href="add_announcement.php" class="btn btn-primary">
                    <i class="fas fa-bullhorn"></i> New Announcement
                </a>
            </div>

            <?php if(isset($_GET['msg'])): ?>
                <div style="background:#d4edda; color:#155724; padding:10px; border-radius:5px; margin-bottom:15px;">
                    <?= $_GET['msg'] == 'deleted' ? 'Announcement deleted successfully.' : 'Action completed successfully.' ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Message Preview</th>
                            <th>Audience</th>
                            <th>Schedule Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($announcements)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:#777;">No announcements found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($announcements as $data): 
                                
                                // Determine Audience Badge
                                $target = $data['target_audience'] ?? 'all';
                                if ($target === 'driver') {
                                    $badgeClass = 'badge-driver';
                                    $icon = '<i class="fas fa-bus"></i>';
                                    $label = 'Drivers Only';
                                } elseif ($target === 'student') {
                                    $badgeClass = 'badge-student';
                                    $icon = '<i class="fas fa-user-graduate"></i>';
                                    $label = 'Students Only';
                                } else {
                                    $badgeClass = 'badge-all';
                                    $icon = '<i class="fas fa-globe"></i>';
                                    $label = 'Everyone';
                                }

                                // Status Display Logic
                                $status = $data['status'] ?? 'sent';
                                if ($status === 'sent') {
                                    $statusColor = 'var(--success)';
                                    $statusLabel = 'Sent';
                                } else {
                                    $statusColor = '#f0ad4e'; // Warning/Pending Color
                                    $statusLabel = 'Scheduled';
                                }
                            ?>
                            <tr>
                                <td style="font-weight:600; color:var(--primary-blue);"><?= htmlspecialchars($data['title'] ?? 'No Title') ?></td>
                                
                                <td style="color:#555; font-size:0.9rem;">
                                    <?= htmlspecialchars(substr($data['message'] ?? '', 0, 40)) ?>...
                                </td>
                                
                                <td>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= $icon ?> <?= $label ?>
                                    </span>
                                </td>
                                
                                <td style="font-size:0.9rem;">
                                    <?php if(!empty($data['schedule_time'])): ?>
                                        <i class="far fa-clock"></i> <?= date('d M, h:i A', strtotime($data['schedule_time'])) ?>
                                    <?php else: ?>
                                        <span style="color:#aaa;">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <span class="badge" style="background:<?= $statusColor ?>; color:white;">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <div style="display:flex; gap:5px;">
                                        <a href="update_announcement.php?id=<?= $data['id'] ?>" class="btn" style="padding:5px 10px; font-size:0.8rem;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" onsubmit="return confirm('Delete this announcement?');" style="display:inline;">
                                            <input type="hidden" name="delete_id" value="<?= $data['id'] ?>">
                                            <button type="submit" class="btn danger" style="padding:5px 10px; font-size:0.8rem;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
</body>
</html>