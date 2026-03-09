<?php
session_start();
require_once '../../config.php';

// Admin-only access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$search = trim($_GET['search'] ?? '');

// Pagination
$limit = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$startIndex = ($page - 1) * $limit;

// Fetch students
$studentsSnapshot = $firestore->database()->collection('Students')->documents();
$allStudents = [];

foreach ($studentsSnapshot as $student) {
    $data = $student->data();
    $data['id'] = $student->id();

    if ($search) {
        $q = strtolower($search);
        if (
            str_contains(strtolower($data['full_name'] ?? ''), $q) ||
            str_contains(strtolower($data['student_email'] ?? ''), $q) ||
            str_contains(strtolower($data['student_id'] ?? ''), $q)
        ) {
            $allStudents[] = $data;
        }
    } else {
        $allStudents[] = $data;
    }
}

$totalStudents = count($allStudents);
$totalPages = ceil($totalStudents / $limit);
$students = array_slice($allStudents, $startIndex, $limit);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Students Management - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>

<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 class="page-title">Students Management</h2>
            </div>

            <div class="card" style="padding: 20px;">
                <form method="GET" style="display:flex; gap:10px;">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search by name, email, or ID..."
                           value="<?= htmlspecialchars($search) ?>"
                           style="margin:0; flex:1;">
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    
                    <button type="button" class="btn" style="background:#ccc; color:#333;" 
                            onclick="window.location.href='students_management.php'">
                        Reset
                    </button>
                </form>
            </div>

            <div class="card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Student ID</th>
                            <th>Status</th>
                            <th>Profile</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding: 20px; color:#888;">
                                No students found.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($students as $student): 
                            $photoUrl = $student['photo_url'] ?? '';
                            $fallback = "https://cdn-icons-png.flaticon.com/512/149/149071.png";
                            $displayImg = !empty($photoUrl) ? $photoUrl : $fallback;
                        ?>
                        <tr>
                            <td>
                                <img src="<?= htmlspecialchars($displayImg) ?>"
                                     alt="Photo"
                                     style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:1px solid #ddd;"
                                     onerror="this.src='<?= $fallback ?>';">
                            </td>
                            <td style="font-weight:600;"><?= htmlspecialchars($student['full_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($student['student_email'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($student['phone_number'] ?? '-') ?></td>
                            <td><span class="badge" style="background:#eee; padding:2px 6px; border-radius:4px; font-size:0.85em;"><?= htmlspecialchars($student['student_id'] ?? '-') ?></span></td>

                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           <?= ($student['status'] ?? '') === 'active' ? 'checked' : '' ?>
                                           onchange="toggleStudentStatus(this, '<?= $student['id'] ?>')">
                                    <span class="slider"></span>
                                </label>
                            </td>

                            <td>
                                <?php if (!empty($student['has_completed_profile'])): ?>
                                    <span style="color:var(--success); font-weight:bold;"><i class="fas fa-check-circle"></i> Yes</span>
                                <?php else: ?>
                                    <span style="color:#999;">No</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <a href="view_student.php?id=<?= $student['id'] ?>" class="btn" title="View Details" style="padding:6px 10px; font-size:0.9rem; background: var(--primary-blue); color: white; border-radius: 4px;">
                                        <i class="fas fa-eye"></i> 
                                    </a>

                                    <?php if (($student['status'] ?? '') === 'inactive'): ?>
                                        <a href="delete_student.php?id=<?= $student['id'] ?>" 
                                           class="btn danger" 
                                           title="Delete Student"
                                           style="padding:6px 10px; font-size:0.9rem; border-radius: 4px;"
                                           onclick="return confirm('Delete this student permanently? This cannot be undone.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn" disabled style="padding:6px 10px; font-size:0.9rem; background: #eee; color: #ccc; cursor: not-allowed; border-radius: 4px;" title="Deactivate first to delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                <div style="margin-top:20px; text-align:center;">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"
                           class="btn"
                           style="margin:0 2px; <?= $p == $page ? 'background-color:var(--primary-blue); color:white;' : 'background-color:#eee; color:#333;' ?>">
                           <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script src="manage_student.js"></script>

</body>
</html>