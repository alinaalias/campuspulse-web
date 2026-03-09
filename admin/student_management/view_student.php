<?php
require_once '../../config.php';
session_start();

// Admin-only check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: students_management.php?msg=error');
    exit();
}

// Fetch Student Data
$studentRef = $firestore->database()->collection('Students')->document($id);
$studentSnap = $studentRef->snapshot();

if (!$studentSnap->exists()) {
    header('Location: students_management.php?msg=notfound');
    exit();
}

$student = $studentSnap->data();
$student['id'] = $studentSnap->id();

// HANDLE STATUS CHANGE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['status'];
    $studentRef->update([
        ['path' => 'status', 'value' => $newStatus],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ]);
    // Refresh page
    header("Location: view_student.php?id=$id&msg=updated");
    exit();
}

// Helper for dates
function formatDate($timestamp) {
    if (!$timestamp) return 'N/A';
    // Handle Firestore Timestamp or String
    if (is_object($timestamp) && method_exists($timestamp, 'get')) {
        return date('d M Y, h:i A', $timestamp->get()->getTimestamp()); 
    }
    return date('d M Y, h:i A', strtotime($timestamp));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Student - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    
    <style>
        .profile-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .profile-img {
            width: 100px; height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        .info-group label {
            font-size: 0.85rem;
            color: #888;
            font-weight: 600;
            text-transform: uppercase;
            display: block;
            margin-bottom: 5px;
        }
        .info-group p {
            font-size: 1rem;
            color: #333;
            font-weight: 500;
            margin: 0;
        }
        .timetable-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        .day-row {
            display: flex;
            margin-bottom: 15px;
            align-items: flex-start;
        }
        .day-label {
            width: 60px;
            font-weight: 700;
            color: var(--primary-blue);
            padding-top: 5px;
        }
        .time-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            flex: 1;
        }
        .time-chip {
            background: white;
            border: 1px solid #ddd;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #555;
        }
        .status-form {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            
            <div style="margin-bottom: 20px;">
                <a href="students_management.php" class="btn" style="background:transparent; color:#666; padding-left:0;">
                    <i class="fas fa-arrow-left"></i> Back to Students List
                </a>
            </div>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
                <div style="background:#d4edda; color:#155724; padding:15px; border-radius:8px; margin-bottom:20px;">
                    <i class="fas fa-check-circle"></i> Student status updated successfully.
                </div>
            <?php endif; ?>

            <div class="card">
                
                <div class="profile-header">
                    <img src="<?= htmlspecialchars($student['photo_url'] ?? '../../assets/default-user.png') ?>" 
                         class="profile-img" 
                         onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
                    
                    <div style="flex: 1;">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <div>
                                <h2 style="margin:0; color:var(--primary-blue);"><?= htmlspecialchars($student['full_name']) ?></h2>
                                <p style="margin:5px 0 0; color:#666;">
                                    <i class="fas fa-id-card"></i> <?= htmlspecialchars($student['student_id']) ?>
                                    <span style="margin:0 10px;">•</span>
                                    @<?= htmlspecialchars($student['username'] ?? 'unknown') ?>
                                </p>
                            </div>
                            
                            <div class="status-badge">
                                <?php if (($student['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge" style="background:var(--success); color:white; padding:6px 12px; border-radius:20px;">Active</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--danger); color:white; padding:6px 12px; border-radius:20px;">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-group">
                        <label>Email Address</label>
                        <p><a href="mailto:<?= htmlspecialchars($student['student_email']) ?>" style="color:var(--primary-blue); text-decoration:none;">
                            <?= htmlspecialchars($student['student_email']) ?>
                        </a></p>
                    </div>
                    <div class="info-group">
                        <label>Phone Number</label>
                        <p><?= htmlspecialchars($student['phone_number']) ?></p>
                    </div>
                    <div class="info-group">
                        <label>Registered On</label>
                        <p><?= formatDate($student['registration_date'] ?? $student['created_at'] ?? null) ?></p>
                    </div>
                    <div class="info-group">
                        <label>Profile Completion</label>
                        <p>
                            <?php if (!empty($student['has_completed_profile'])): ?>
                                <span style="color:var(--success);"><i class="fas fa-check-circle"></i> Complete</span>
                            <?php else: ?>
                                <span style="color:#f39c12;"><i class="fas fa-exclamation-circle"></i> Incomplete</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <h4 style="color:var(--primary-blue); border-bottom:2px solid #eee; padding-bottom:10px; margin-top:30px;">
                    <i class="far fa-calendar-alt"></i> Class Timetable
                </h4>
                
                <div class="timetable-section">
                    <?php 
                        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                        $hasTimetable = false;
                        
                        foreach ($days as $day): 
                            $slots = $student['timetable'][$day] ?? [];
                            if (!empty($slots)) $hasTimetable = true;
                    ?>
                        <div class="day-row" style="<?= empty($slots) ? 'opacity:0.4;' : '' ?>">
                            <div class="day-label"><?= $day ?></div>
                            <div class="time-chips">
                                <?php if (empty($slots)): ?>
                                    <span style="color:#aaa; font-size:0.85rem; font-style:italic; padding-top:5px;">No classes</span>
                                <?php else: ?>
                                    <?php foreach ($slots as $time): ?>
                                        <div class="time-chip"><?= htmlspecialchars($time) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$hasTimetable): ?>
                        <div style="text-align:center; padding:20px; color:#888;">
                            Timetable data not set.
                        </div>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 40px; border-top:1px solid #eee; padding-top:20px;">
                    <h4 style="color:#333; margin-bottom:15px;">Account Management</h4>
                    
                    <form method="POST" class="status-form">
                        <div>
                            <strong style="color:#856404;">Change Account Status</strong>
                            <div style="font-size:0.85rem; color:#856404; margin-top:2px;">
                                Deactivating will prevent the student from logging in.
                            </div>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <select name="status" class="form-control" style="margin:0; width:150px; background:white;">
                                <option value="active" <?= ($student['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($student['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

</body>
</html>