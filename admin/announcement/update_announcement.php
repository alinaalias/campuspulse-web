<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? '';
if(!$id) { header("Location: announcements_management.php"); exit(); }

$docRef = $firestore->database()->collection('Announcements')->document($id);
$snap = $docRef->snapshot();

if(!$snap->exists()) { header("Location: announcements_management.php"); exit(); }
$data = $snap->data();

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $target = $_POST['target_audience'];
    $schedule = !empty($_POST['schedule_time']) ? $_POST['schedule_time'] : null;

    if (empty($title) || empty($message)) {
        $error = "Title and message are required.";
    } else {
        $status = ($schedule && strtotime($schedule) > time()) ? 'pending' : 'sent';
        
        $docRef->set([
            'title' => $title,
            'message' => $message,
            'target_audience' => $target,
            'schedule_time' => $schedule,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['merge' => true]);
        
        header("Location: announcements_management.php?msg=updated");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Announcement - CampusPulse</title>
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
            <div class="card" style="max-width: 600px; margin: 0 auto;">
                <h2 style="color:var(--primary-blue); margin-bottom: 20px;">Edit Announcement</h2>
                
                <?php if($error): ?>
                    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Subject / Title</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($data['title']) ?>" required>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Message Content</label>
                        <textarea name="message" class="form-control" rows="5" required><?= htmlspecialchars($data['message']) ?></textarea>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <label style="font-weight:600;">Target Audience</label>
                            <select name="target_audience" class="form-control">
                                <option value="all" <?= ($data['target_audience'] ?? '') =='all'?'selected':'' ?>>📢 Everyone</option>
                                <option value="driver" <?= ($data['target_audience'] ?? '') =='driver'?'selected':'' ?>>🚌 Drivers Only</option>
                                <option value="student" <?= ($data['target_audience'] ?? '') =='student'?'selected':'' ?>>🎓 Students Only</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600;">Schedule Post</label>
                            <input type="datetime-local" name="schedule_time" class="form-control" 
                                   value="<?= isset($data['schedule_time']) ? date('Y-m-d\TH:i', strtotime($data['schedule_time'])) : '' ?>">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:30px;">
                        <a href="announcements_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Announcement</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>