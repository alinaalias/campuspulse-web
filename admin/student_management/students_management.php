<?php
session_start();
require_once '../../config.php';

// Admin-only access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// Fetch all students (Removed PHP search and pagination to allow instant JS filtering)
$studentsSnapshot = $firestore->collection('Students')->documents();
$students = [];

foreach ($studentsSnapshot as $student) {
    $data = $student->data();
    $data['id'] = $student->id();
    $students[] = $data;
}

$pageTitle = 'Students Management - CampusPulse';
$depth = '../../';
include $depth . 'layout/admin/header.php';
?>

<style>
    /* Instant Search hidden class */
    .search-hidden {
        display: none !important;
    }

    .student-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1005;
        align-items: center;
        justify-content: center;
    }

    .student-modal-card {
        background: white;
        width: 90%;
        max-width: 450px;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        position: relative;
        animation: slideUp 0.3s ease;
    }

    .student-modal-card.theme-activate {
        border-top: 5px solid var(--success, #2ecc71);
    }

    .student-modal-card.theme-deactivate {
        border-top: 5px solid var(--danger, #e74c3c);
    }

    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2 class="page-title">Students Management</h2>
</div>

<div class="card" style="margin-bottom: 20px; padding: 15px;">
    <div style="display: flex; gap: 10px; align-items: center; border: 1px solid #cbd5e1; padding: 5px 15px; border-radius: 8px; background: white; transition: 0.2s;"
        id="searchBoxContainer">
        <i class="fas fa-search" style="color: #94a3b8; font-size: 1.1rem;"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Search Name, Email, or Student ID..."
            style="margin: 0; flex: 1; border: none; box-shadow: none; outline: none; background: transparent; font-size: 0.95rem;">
    </div>
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
        <tbody id="studentsTableBody">
            <?php if (empty($students)): ?>
                <tr id="defaultEmptyRow">
                    <td colspan="8" style="text-align:center; padding: 20px; color:#888;">
                        No students found in the database.
                    </td>
                </tr>
            <?php endif; ?>

            <tr id="noMatchesRow" style="display: none;">
                <td colspan="8" style="text-align:center; padding: 40px 20px; color:#888;">
                    <i class="fas fa-search"
                        style="font-size: 2rem; color: #ddd; margin-bottom: 10px; display:block;"></i>
                    <h3 style="margin:0; color: #555; font-size: 1.1rem;">No matches found</h3>
                    <p style="margin-top: 5px; font-size: 0.9rem;">Try adjusting your search query.</p>
                </td>
            </tr>

            <?php foreach ($students as $student):
                // REVERTED: Firebase strictly rejects extra query params, so we load the raw URL.
                $photoUrl = $student['photo_url'] ?? '';
                $fallback = "https://cdn-icons-png.flaticon.com/512/149/149071.png";
                $displayImg = !empty($photoUrl) ? $photoUrl : $fallback;

                // 2. DYNAMIC PROFILE COMPLETION VERIFICATION
                $dbCompleted = $student['has_completed_profile'] ?? false;
                $hasName = !empty($student['full_name']);
                $hasPhone = !empty($student['phone_number']);
                $hasId = !empty($student['student_id']);
                $hasPhoto = !empty($student['photo_url']);

                $hasClasses = false;
                if (!empty($student['timetable']) && is_array($student['timetable'])) {
                    foreach ($student['timetable'] as $day => $classes) {
                        if (!empty($classes)) {
                            $hasClasses = true;
                            break;
                        }
                    }
                }
                // Override boolean if they physically filled out all the necessary data
                $isProfileComplete = $dbCompleted || ($hasName && $hasPhone && $hasId && $hasPhoto && $hasClasses);
                ?>
                <tr class="student-row">
                    <td>
                        <img src="<?= htmlspecialchars($displayImg) ?>" alt="Photo"
                            style="width:40px; height:40px; border-radius:50%; object-fit:cover; border:1px solid #ddd;"
                            onerror="this.src='<?= $fallback ?>';">
                    </td>
                    <td style="font-weight:600;"><?= htmlspecialchars($student['full_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($student['student_email'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($student['phone_number'] ?? '-') ?></td>
                    <td><span class="badge"
                            style="background:#eee; padding:2px 6px; border-radius:4px; font-size:0.85em;"><?= htmlspecialchars($student['student_id'] ?? '-') ?></span>
                    </td>

                    <td>
                        <label class="switch">
                            <input type="checkbox" <?= ($student['status'] ?? '') === 'active' ? 'checked' : '' ?>
                                onchange="toggleStudentStatus(this, '<?= $student['id'] ?>')">
                            <span class="slider"></span>
                        </label>
                    </td>

                    <td>
                        <?php if ($isProfileComplete): ?>
                            <span style="color:var(--success); font-weight:bold;"><i class="fas fa-check-circle"></i> Yes</span>
                        <?php else: ?>
                            <span style="color:#f39c12;"><i class="fas fa-exclamation-circle"></i> No</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <a href="view_student.php?id=<?= $student['id'] ?>" class="btn" title="View Details"
                                style="padding:6px 10px; font-size:0.9rem; background: var(--primary-blue); color: white; border-radius: 4px;">
                                <i class="fas fa-eye"></i>
                            </a>

                            <?php if (($student['status'] ?? '') === 'inactive'): ?>
                                <a href="delete_student.php?id=<?= $student['id'] ?>" class="btn danger" title="Delete Student"
                                    style="padding:6px 10px; font-size:0.9rem; border-radius: 4px;"
                                    onclick="return confirm('Delete this student permanently? This cannot be undone.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            <?php else: ?>
                                <button class="btn" disabled
                                    style="padding:6px 10px; font-size:0.9rem; background: #eee; color: #ccc; cursor: not-allowed; border-radius: 4px;"
                                    title="Deactivate first to delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="studentStatusReasonModal" class="student-modal-overlay" onclick="closeStudentStatusModalEvent(event)">
    <div class="student-modal-card" id="studentStatusModalCard">
        <i class="fas fa-times"
            style="position:absolute; top:20px; right:20px; cursor:pointer; color:#888; font-size:1.2rem;"
            onclick="closeStudentStatusModal()"></i>
        <h3 id="studentStatusModalTitle" style="margin-top: 0; color: #333;">Change Status</h3>
        <p id="studentStatusModalDesc" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>

        <div class="form-group" style="margin-bottom: 20px;">
            <label style="font-size:0.8rem; font-weight:600; color:#555; display:block; margin-bottom:8px;">Reason for
                Action</label>
            <select id="studentStatusReasonSelect" class="form-control" onchange="checkStudentReasonSelection()"
                style="width: 100%; padding: 10px; font-size: 0.95rem; border: 1px solid #ddd; border-radius: 8px;">
                <option value="">-- Select a reason --</option>
            </select>
        </div>

        <button id="confirmStudentStatusBtn" class="btn btn-primary"
            style="width:100%; padding: 12px; font-weight: 600;" disabled onclick="confirmStudentStatusChange()">Confirm
            Status Change</button>
    </div>
</div>

<script src="manage_student.js?v=<?= time() ?>"></script>

<script>
    document.getElementById('searchInput').addEventListener('input', function (e) {
        const query = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#studentsTableBody tr.student-row');
        const container = document.getElementById('searchBoxContainer');
        const noMatchesRow = document.getElementById('noMatchesRow');
        const defaultEmptyRow = document.getElementById('defaultEmptyRow');

        // Input active UI styling
        if (query.length > 0) {
            container.style.borderColor = '#3b82f6';
            container.style.boxShadow = '0 0 0 3px rgba(59, 130, 246, 0.1)';
        } else {
            container.style.borderColor = '#cbd5e1';
            container.style.boxShadow = 'none';
        }

        let hasVisibleRows = false;

        // Loop through all table rows and search their inner text
        rows.forEach(row => {
            const rowText = row.innerText.toLowerCase();

            if (rowText.includes(query)) {
                row.classList.remove('search-hidden');
                hasVisibleRows = true;
            } else {
                row.classList.add('search-hidden');
            }
        });

        // Manage Empty States
        if (defaultEmptyRow && !query) {
            defaultEmptyRow.style.display = ''; // Show default "database empty"
            noMatchesRow.style.display = 'none';
        } else if (defaultEmptyRow && query) {
            defaultEmptyRow.style.display = 'none';
        }

        if (!hasVisibleRows && rows.length > 0) {
            noMatchesRow.style.display = ''; // Show "No matches found"
        } else {
            noMatchesRow.style.display = 'none';
        }
    });
</script>

<?php include $depth . 'layout/admin/footer.php'; ?>