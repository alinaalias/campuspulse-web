<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// 1. Get Search Term
$search = trim($_GET['search'] ?? '');

/* Fetch shuttles */
$shuttles = [];
foreach ($firestore->database()->collection('Shuttles')->where('status', '=', 'active')->documents() as $s) {
    $shuttles[] = $s->id();
}

/* Fetch drivers */
$driversSnapshot = $firestore->database()->collection('Staffs')->where('role', '=', 'driver')->documents();
$drivers = [];

foreach ($driversSnapshot as $doc) {
    $data = $doc->data();
    $data['id'] = $doc->id();
    
    // 2. Filter Logic
    // If there is a search term, only add drivers that match
    if ($search) {
        $term = strtolower($search);
        $name = strtolower($data['full_name'] ?? '');
        $id = strtolower($data['id']);
        $shuttle = strtolower($data['assigned_shuttle_id'] ?? '');

        // Check if search term exists in Name, ID, or Shuttle
        if (str_contains($name, $term) || str_contains($id, $term) || str_contains($shuttle, $term)) {
            $drivers[] = $data;
        }
    } else {
        // No search? Add everyone.
        $drivers[] = $data;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drivers Management - CampusPulse</title>
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
                <h2 class="page-title">Drivers Management</h2>
                <a href="add_driver.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Add Driver
                </a>
            </div>

            <div class="card" style="margin-bottom: 20px; padding: 15px;">
                <form method="GET" style="display: flex; gap: 10px;">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by Name, ID, or Shuttle..." 
                           value="<?= htmlspecialchars($search) ?>" 
                           style="margin-bottom: 0; flex: 1;">
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    
                    <?php if($search): ?>
                        <a href="drivers_management.php" class="btn" style="background: #eee; color: #333; text-decoration: none; display: flex; align-items: center;">
                            Reset
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if(isset($_GET['msg'])): ?>
                <?php 
                    $msg = $_GET['msg'];
                    $bgColor = '#2ecc71'; 
                    $text = "";
                    switch($msg) {
                        case 'added': $text = "Driver successfully registered!"; break;
                        case 'updated': $text = "Driver details updated."; break;
                        case 'deleted': $text = "Driver deleted."; $bgColor = '#e74c3c'; break;
                        case 'active': $text = "Cannot delete active driver. Deactivate first."; $bgColor = '#f39c12'; break;
                        case 'error': $text = "An error occurred."; $bgColor = '#e74c3c'; break;
                    }
                ?>
                <?php if($text): ?>
                    <div style="padding:15px; margin-bottom:20px; border-radius:6px; color:white; background: <?= $bgColor ?>;">
                        <?= htmlspecialchars($text) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Profile</th>
                            <th>Driver ID</th>
                            <th>Details</th>
                            <th>Assigned Shuttle</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($drivers)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px; color: #777;">
                                    No drivers found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($drivers as $driver): 
                                // --- GLITCH-FREE IMAGE LOGIC ---
                                $dbPhoto = $driver['photo_url'] ?? ($driver['profile_pic'] ?? '');
                                $fallback = "https://cdn-icons-png.flaticon.com/512/149/149071.png";
                                
                                if (empty($dbPhoto)) {
                                    $displayImg = $fallback;
                                } elseif (strpos($dbPhoto, 'http') === 0) {
                                    $displayImg = $dbPhoto; 
                                } else {
                                    $cleanPath = urlencode($dbPhoto);
                                    $displayImg = "https://firebasestorage.googleapis.com/v0/b/campuspulse-bfd09.firebasestorage.app/o/{$cleanPath}?alt=media";
                                }
                            ?>
                            <tr>
                                <td>
                                    <img src="<?= htmlspecialchars($displayImg) ?>"
                                         style="width:45px; height:45px; border-radius:50%; object-fit:cover; border:2px solid #eee; background:#fafafa;"
                                         alt="Img">
                                </td>
                                
                                <td style="font-weight:600; color:var(--primary-blue);">
                                    <?= htmlspecialchars($driver['id']) ?>
                                </td>
                                
                                <td>
                                    <div style="font-weight:600;"><?= htmlspecialchars($driver['full_name']) ?></div>
                                    <div style="font-size:0.85rem; color:#777;"><?= htmlspecialchars($driver['phone_number']) ?></div>
                                    <div style="font-size:0.8rem; color:#999;"><?= htmlspecialchars($driver['email']) ?></div>
                                </td>

                                <td>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <select id="shuttle-<?= $driver['id'] ?>" disabled class="form-control" style="width:120px; padding:5px; font-size:0.9rem; margin:0;">
                                            <option value="">-- None --</option>
                                            <?php foreach ($shuttles as $sid): ?>
                                                <option value="<?= $sid ?>" <?= ($driver['assigned_shuttle_id'] ?? '') === $sid ? 'selected' : '' ?>>
                                                    <?= $sid ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button class="btn" onclick="enableEdit('<?= $driver['id'] ?>')" title="Edit Assignment" style="padding:5px 8px;">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <button id="save-<?= $driver['id'] ?>" class="btn btn-primary" onclick="saveAssignment('<?= $driver['id'] ?>')" disabled style="padding:5px 8px;">
                                            <i class="fas fa-save"></i>
                                        </button>
                                    </div>
                                </td>

                                <td>
                                    <label class="switch">
                                        <input type="checkbox"
                                               <?= ($driver['status'] ?? '') === 'active' ? 'checked' : '' ?>
                                               onchange="toggleStatus(this, '<?= $driver['id'] ?>')">
                                        <span class="slider"></span>
                                    </label>
                                </td>

                                <td>
                                    <?php if (($driver['status'] ?? '') === 'inactive'): ?>
                                        <a href="delete_driver.php?id=<?= $driver['id'] ?>" class="btn danger" style="padding:5px 10px; font-size:0.8rem;" onclick="return confirm('Permanently delete this driver?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#ccc; font-size:0.8rem;" title="Deactivate first"><i class="fas fa-trash"></i></span>
                                    <?php endif; ?>
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

<script src="manage_driver.js"></script>

</body>
</html>