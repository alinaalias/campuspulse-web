<?php
session_start();
require_once '../../config.php';

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

/* =========================
   FETCH ZONES (ID → NAME)
========================= */
$zoneMap = [];
$zonesSnapshot = $firestore->database()->collection('Zones')->documents();
foreach ($zonesSnapshot as $z) {
    $zoneMap[$z->id()] = $z->data()['name'] ?? 'Unknown';
}

/* =========================
   FETCH SHUTTLES
========================= */
$shuttles = $firestore->database()->collection('Shuttles')->documents();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shuttle Management - CampusPulse</title>
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
                <h2 class="page-title">Shuttle Management</h2>
                <a href="add_shuttle.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Shuttle
                </a>
            </div>

            <?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
                <?php 
                    $msg = $_GET['msg'] ?? '';
                    $err = $_GET['err'] ?? '';
                    $alertClass = $err ? 'alert-error' : 'alert-success'; 
                    $displayText = '';

                    // Success Messages
                    switch ($msg) {
                        case 'added': $displayText = "Shuttle successfully added!"; break;
                        case 'updated': $displayText = "Shuttle details updated."; break;
                        case 'inactive': $displayText = "Shuttle deactivated successfully."; break;
                    }

                    // Error Messages
                    if ($err === 'failed') $displayText = "Action failed. Please try again.";
                ?>

                <?php if ($displayText): ?>
                    <div style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
                        <i class="fas <?php echo $err ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo htmlspecialchars($displayText); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Shuttle ID</th>
                            <th>Assigned Zone</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($shuttles->isEmpty()): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:20px; color:#777;">
                                    No shuttles found. Click "Add Shuttle" to start.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($shuttles as $doc): 
                                $s = $doc->data();
                                // Resolve zone name
                                $zoneName = (!empty($s['zone_id']) && isset($zoneMap[$s['zone_id']])) 
                                            ? $zoneMap[$s['zone_id']] 
                                            : '<span style="color:#ccc">Unassigned</span>';
                            ?>
                            <tr>
                                <td style="font-weight:600; color:var(--primary-blue);">
                                    <?= htmlspecialchars($doc->id()) ?>
                                </td>
                                
                                <td><?= $zoneName ?></td>
                                
                                <td><?= htmlspecialchars($s['capacity'] ?? '-') ?> Seats</td>
                                
                                <td>
                                    <?php if (($s['status'] ?? '') === 'active'): ?>
                                        <span class="badge" style="background:var(--success); color:white; padding:4px 10px; border-radius:15px; font-size:0.8rem;">Active</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#999; color:white; padding:4px 10px; border-radius:15px; font-size:0.8rem;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <a href="edit_shuttle.php?id=<?= $doc->id() ?>" class="btn" style="padding:5px 10px; font-size:0.8rem;">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    
                                    <?php if (($s['status'] ?? '') === 'active'): ?>
                                        <a href="delete_shuttle.php?id=<?= $doc->id() ?>" 
                                           class="btn danger" 
                                           style="padding:5px 10px; font-size:0.8rem;"
                                           onclick="return confirm('Are you sure you want to deactivate this shuttle?')">
                                           <i class="fas fa-ban"></i> Deactivate
                                        </a>
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

</body>
</html>