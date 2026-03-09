<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

/* ====================== FETCH DATA ====================== */
$zones = [];
$zonesSnap = $firestore->database()->collection('Zones')->documents();
foreach ($zonesSnap as $z) { $zones[$z->id()] = $z->data(); }

$stops = [];
$stopsSnap = $firestore->database()->collection('Stops')->documents();
foreach ($stopsSnap as $s) { $stops[$s->id()] = $s->data(); }

$routesSnap = $firestore->database()->collection('Routes')->documents();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Management - CampusPulse</title>
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
            
            <h2 class="page-title">Routes Management</h2>

            <?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
                <?php 
                    $msg = $_GET['msg'] ?? '';
                    $err = $_GET['err'] ?? '';
                    $alertClass = $err ? 'alert-error' : 'alert-success'; 
                    $icon = $err ? 'fa-exclamation-circle' : 'fa-check-circle';
                    $displayText = '';

                    // Define Success Messages
                    switch ($msg) {
                        case 'route_added': $displayText = "Route created successfully!"; break;
                        case 'route_updated': $displayText = "Route updated successfully!"; break;
                        case 'route_deleted': $displayText = "Route deleted successfully."; break;
                        case 'stop_added': $displayText = "Stop added successfully!"; break;
                        case 'stop_updated': $displayText = "Stop details updated."; break;
                        case 'stop_deleted': $displayText = "Stop removed."; break;
                        case 'zone_added': $displayText = "Zone created successfully!"; break;
                        case 'zone_updated': $displayText = "Zone details updated."; break;
                        case 'zone_deleted': $displayText = "Zone removed."; break;
                    }

                    // Define Error Messages
                    switch ($err) {
                        case 'db_error': $displayText = "Database error occurred. Please try again."; break;
                        case 'missing_id': $displayText = "Error: Item ID missing."; break;
                        case 'not_found': $displayText = "Error: Item not found in database."; break;
                        case 'failed': $displayText = "Action failed. Please try again."; break;
                    }
                ?>

                <?php if ($displayText): ?>
                    <div style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
                        <i class="fas <?php echo $icon; ?>"></i>
                        <span><?php echo htmlspecialchars($displayText); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div style="margin-bottom: 25px; display:flex; gap:10px;">
                <a href="#section-routes" class="btn" style="background:var(--primary-blue); color:white;">Routes</a>
                <a href="#section-stops" class="btn" style="background:var(--primary-blue); color:white;">Stops</a>
                <a href="#section-zones" class="btn" style="background:var(--primary-blue); color:white;">Zones</a>
            </div>

            <div id="section-routes" class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                    <h3 style="color:var(--primary-blue); margin:0;">Active Routes</h3>
                    <a href="add_route.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Route</a>
                </div>

                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Route Name</th>
                            <th>Zone</th>
                            <th>Type</th>
                            <th>Stops Covered</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routesSnap as $r): $route = $r->data(); ?>
                        <tr>
                            <td style="font-weight:bold;"><?= htmlspecialchars($route['route_id']) ?></td>
                            <td style="color:var(--primary-blue); font-weight:600;"><?= htmlspecialchars($route['route_name']) ?></td>
                            <td><span class="badge" style="background:#eee; padding:2px 8px; border-radius:4px;"><?= htmlspecialchars($zones[$route['zone_id']]['name'] ?? $route['zone_id']) ?></span></td>
                            <td><?= ucfirst(str_replace('_', ' ', $route['service_type'])) ?></td>
                            
                            <td style="font-size:0.85rem; color:#666;">
                                <?php 
                                $count = 0;
                                foreach ($route['stop_ids'] as $sid) {
                                    $count++;
                                    if($count > 3) { echo "..."; break; } // Truncate if too many
                                    echo htmlspecialchars($stops[$sid]['name'] ?? $sid) . ", ";
                                }
                                ?>
                            </td>

                            <td>
                                <?= ($route['status'] === 'active') 
                                    ? '<span style="color:var(--success); font-weight:bold;">Active</span>' 
                                    : '<span style="color:#999;">Inactive</span>' ?>
                            </td>
                            <td>
                                <a href="edit_route.php?id=<?= $r->id() ?>" class="btn" style="padding:4px 8px;"><i class="fas fa-edit"></i></a>
                                <a href="delete_route.php?id=<?= $r->id() ?>" class="btn danger" style="padding:4px 8px;" onclick="return confirm('Delete this route?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="section-stops" class="card" style="margin-top:40px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                    <h3 style="color:var(--primary-blue); margin:0;">Stops</h3>
                    <a href="add_stop.php" class="btn btn-primary"><i class="fas fa-map-marker-alt"></i> Add Stop</a>
                </div>

                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Linked Zones</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stops as $id => $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['stop_id']) ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></td>
                            <td style="font-size:0.85rem; color:#777;">
                                <i class="fas fa-location-arrow"></i> <?= number_format($s['lat'], 4) ?>, <?= number_format($s['lng'], 4) ?>
                            </td>
                            <td>
                                <?php if (!empty($s['zone_ids'])): foreach ($s['zone_ids'] as $zid): ?>
                                    <span style="background:#e3f2fd; color:var(--primary-blue); padding:2px 6px; border-radius:4px; font-size:0.75rem;">
                                        <?= htmlspecialchars($zones[$zid]['name'] ?? $zid) ?>
                                    </span>
                                <?php endforeach; endif; ?>
                            </td>
                            <td>
                                <a href="edit_stop.php?id=<?= $id ?>" class="btn" style="padding:4px 8px;"><i class="fas fa-edit"></i></a>
                                <a href="delete_stop.php?id=<?= $id ?>" class="btn danger" style="padding:4px 8px;" onclick="return confirm('Delete stop?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="section-zones" class="card" style="margin-top:40px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                    <h3 style="color:var(--primary-blue); margin:0;">Zones</h3>
                    <a href="add_zone.php" class="btn btn-primary"><i class="fas fa-draw-polygon"></i> Add Zone</a>
                </div>

                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Zone Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zones as $id => $z): ?>
                        <tr>
                            <td><?= htmlspecialchars($z['zone_id']) ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($z['name']) ?></td>
                            <td style="color:#666; font-size:0.9rem;"><?= htmlspecialchars($z['description'] ?? '-') ?></td>
                            <td>
                                <?= ($z['status'] === 'active') 
                                    ? '<span style="color:var(--success);">Active</span>' 
                                    : '<span style="color:#999;">Inactive</span>' ?>
                            </td>
                            <td>
                                <a href="edit_zone.php?id=<?= $id ?>" class="btn" style="padding:4px 8px;"><i class="fas fa-edit"></i></a>
                                <a href="delete_zone.php?id=<?= $id ?>" class="btn danger" style="padding:4px 8px;" onclick="return confirm('Delete zone?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
</body>
</html>