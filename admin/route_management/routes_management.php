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
foreach ($zonesSnap as $z) {
    $zones[$z->id()] = $z->data();
}

$stops = [];
$stopsSnap = $firestore->database()->collection('Stops')->documents();
foreach ($stopsSnap as $s) {
    $stops[$s->id()] = $s->data();
}

$routesSnap = $firestore->database()->collection('Routes')->documents();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Route Management - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        /* Smooth scrolling for anchor links */
        html {
            scroll-behavior: smooth;
        }

        /* --- BACK TO TOP BUTTON --- */
        #backToTopBtn {
            display: none;
            position: fixed;
            bottom: 40px;
            right: 40px;
            z-index: 999;
            font-size: 1.2rem;
            border: none;
            outline: none;
            background-color: var(--primary-blue);
            color: white;
            cursor: pointer;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
        }

        #backToTopBtn.show {
            opacity: 1;
            pointer-events: auto;
        }

        #backToTopBtn:hover {
            background-color: #1a4971;
            transform: translateY(-5px);
        }

        /* Unified Row Hover Effect */
        table.styled-table tbody tr.searchable-row {
            transition: background-color 0.2s ease;
        }

        table.styled-table tbody tr.searchable-row:hover {
            background-color: #f8f9fa;
        }

        /* Route Timeline Styles */
        .route-timeline {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
        }

        .stop-badge {
            background: #ffffff;
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: #4a5568;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        }

        .stop-badge-start {
            background: #f0fff4;
            border-color: #9ae6b4;
            color: #22543d;
            font-weight: 600;
        }

        .stop-badge-end {
            background: #fff5f5;
            border-color: #feb2b2;
            color: #742a2a;
            font-weight: 600;
        }

        .offset-time {
            color: var(--primary-blue);
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 2px;
        }

        .route-arrow {
            color: #cbd5e0;
            font-size: 0.7rem;
            margin: 0 2px;
        }

        .more-stops {
            background: #edf2f7;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        /* Bulletproof Search Bar & Buttons */
        .header-actions-wrapper {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: nowrap;
        }

        .search-container {
            display: flex;
            align-items: center;
            background: #ffffff;
            border-radius: 8px;
            padding: 0 15px !important;
            width: 260px;
            height: 42px !important;
            border: 1px solid #cbd5e0;
            box-sizing: border-box !important;
        }

        .search-container i {
            color: #a0aec0;
            margin-right: 10px;
            font-size: 0.9rem;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            height: 100%;
        }

        .search-container input {
            border: none !important;
            background: transparent !important;
            outline: none !important;
            flex: 1 !important;
            min-width: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            color: #2d3748;
            height: 100% !important;
        }

        .btn-header-action {
            height: 42px !important;
            padding: 0 20px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-sizing: border-box !important;
            gap: 8px;
            white-space: nowrap;
            margin: 0 !important;
        }

        /* Seamless Clickable Timeline Row */
        .timeline-clickable {
            cursor: pointer;
            border-radius: 6px;
            padding: 12px 15px !important;
            position: relative;
        }

        .click-hint {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.7rem;
            color: #718096;
            font-weight: 500;
            margin-top: 6px;
        }

        /* Modern Action Buttons */
        .action-flex {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: #edf2f7;
            color: #4a5568;
            transition: 0.2s;
        }

        .btn-icon:hover {
            background: #e2e8f0;
            color: var(--primary-blue);
        }

        .btn-icon.danger {
            background: #fff5f5;
            color: #e53e3e;
        }

        .btn-icon.danger:hover {
            background: #fed7d7;
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding: 15px 0 5px 0;
            border-top: 1px solid #edf2f7;
        }

        .pagination-info {
            font-size: 0.85rem;
            color: #718096;
        }

        .pagination-buttons {
            display: flex;
            gap: 4px;
        }

        .page-btn {
            padding: 6px 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: 0.2s;
            color: #4a5568;
            font-weight: 500;
        }

        .page-btn:hover:not(:disabled) {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .page-btn.active {
            background: var(--primary-blue);
            color: #fff;
            border-color: var(--primary-blue);
        }

        .page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 20, 30, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #f4f6f9;
            border: none;
            font-size: 1rem;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            color: #777;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
        }

        .modal-close:hover {
            background: #fee2e2;
            color: #e53e3e;
        }

        .modal-content .route-timeline {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .modal-content .route-arrow {
            transform: rotate(90deg);
            margin-left: 18px;
            color: #a0aec0;
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

                <h2 class="page-title">Routes Management</h2>

                <?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
                    <?php
                    $msg = $_GET['msg'] ?? '';
                    $err = $_GET['err'] ?? '';
                    $alertClass = $err ? 'alert-error' : 'alert-success';
                    $icon = $err ? 'fa-exclamation-circle' : 'fa-check-circle';
                    $displayText = '';

                    switch ($msg) {
                        case 'route_added':
                            $displayText = "Route created successfully!";
                            break;
                        case 'route_updated':
                            $displayText = "Route updated successfully!";
                            break;
                        case 'route_deleted':
                            $displayText = "Route deleted successfully.";
                            break;
                        case 'stop_added':
                            $displayText = "Stop added successfully!";
                            break;
                        case 'stop_updated':
                            $displayText = "Stop details updated.";
                            break;
                        case 'stop_deleted':
                            $displayText = "Stop removed.";
                            break;
                        case 'zone_added':
                            $displayText = "Zone created successfully!";
                            break;
                        case 'zone_updated':
                            $displayText = "Zone details updated.";
                            break;
                        case 'zone_deleted':
                            $displayText = "Zone removed.";
                            break;
                    }

                    switch ($err) {
                        case 'db_error':
                            $displayText = "Database error occurred. Please try again.";
                            break;
                        case 'missing_id':
                            $displayText = "Error: Item ID missing.";
                            break;
                        case 'not_found':
                            $displayText = "Error: Item not found in database.";
                            break;
                        case 'failed':
                            $displayText = "Action failed. Please try again.";
                            break;
                    }
                    ?>

                    <?php if ($displayText): ?>
                        <div
                            style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                            <span><?php echo htmlspecialchars($displayText); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="card" style="margin-bottom: 30px;">
                    <div
                        style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px; flex-wrap: wrap; gap: 10px;">
                        <h3 style="color:var(--primary-blue); margin:0;"><i class="fas fa-globe-asia"
                                style="margin-right:8px;"></i> System Coverage Map</h3>
                        <div id="mapLegend"
                            style="display:flex; gap: 12px; font-size: 0.8rem; flex-wrap: wrap; background: #f8f9fa; padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        </div>
                    </div>
                    <div id="systemMap"
                        style="width: 100%; height: 400px; border-radius: 8px; border: 1px solid #e2e8f0; background: #eaebed;">
                    </div>
                </div>

                <div style="margin-bottom: 25px; display:flex; gap:10px;">
                    <a href="#section-routes" class="btn" style="background:var(--primary-blue); color:white;"><i
                            class="fas fa-route" style="margin-right: 5px;"></i> Routes</a>
                    <a href="#section-stops" class="btn" style="background:var(--primary-blue); color:white;"><i
                            class="fas fa-map-marker-alt" style="margin-right: 5px;"></i> Stops</a>
                    <a href="#section-zones" class="btn" style="background:var(--primary-blue); color:white;"><i
                            class="fas fa-draw-polygon" style="margin-right: 5px;"></i> Zones</a>
                </div>

                <div id="section-routes" class="card">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                        <h3 style="color:var(--primary-blue); margin:0;">Active Routes</h3>

                        <div class="header-actions-wrapper">
                            <div class="search-container">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchRoutes" placeholder="Search routes..."
                                    onkeyup="handleSearch('tableRoutes')">
                            </div>
                            <a href="add_route.php" class="btn btn-primary btn-header-action">
                                <i class="fas fa-plus"></i> Add Route
                            </a>
                        </div>
                    </div>

                    <table class="styled-table" id="tableRoutes">
                        <thead>
                            <tr>
                                <th>Route Info</th>
                                <th>Service & Direction</th>
                                <th>Stops Timeline (Cumulative ETA)</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($routesSnap as $r):
                                $route = $r->data();
                                $dirLabel = ($route['direction'] === 'to_campus') ? '<i class="fas fa-sign-in-alt" style="color:var(--primary-blue)"></i> To Campus' : '<i class="fas fa-sign-out-alt" style="color:#e67e22"></i> From Campus';
                                $typeLabel = ucfirst(str_replace('_', ' ', $route['service_type']));
                                ?>
                                <tr class="searchable-row">
                                    <td>
                                        <div style="font-weight:bold; color:var(--primary-blue);">
                                            <?= htmlspecialchars($route['route_id']) ?>
                                        </div>
                                        <div style="font-weight:600; color:#333; margin-bottom: 4px;">
                                            <?= htmlspecialchars($route['route_name']) ?>
                                        </div>
                                        <span class="badge"
                                            style="background:#eee; padding:2px 8px; border-radius:4px; font-size: 0.75rem;"><?= htmlspecialchars($zones[$route['zone_id']]['name'] ?? $route['zone_id']) ?></span>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; margin-bottom:4px;"><?= $typeLabel ?></div>
                                        <div style="font-size:0.85rem; color:#666;"><?= $dirLabel ?></div>
                                    </td>
                                    <td class="timeline-clickable" onclick="openRouteModal('<?= $route['route_id'] ?>')">
                                        <div class="route-timeline">
                                            <?php
                                            $stopItems = $route['stop_ids'] ?? [];
                                            $totalStops = count($stopItems);
                                            $truncatedHtml = [];
                                            $fullHtml = [];

                                            foreach ($stopItems as $index => $stopItem) {
                                                $actualStopId = is_array($stopItem) ? ($stopItem['stop_id'] ?? '') : $stopItem;
                                                $offset = is_array($stopItem) ? ($stopItem['offset'] ?? '') : '';
                                                $stopName = htmlspecialchars($stops[$actualStopId]['name'] ?? $actualStopId);
                                                $offsetHtml = ($offset !== '' && $index > 0) ? "<span class='offset-time'>+{$offset}m</span>" : "";

                                                $badgeClass = 'stop-badge';
                                                $icon = '';
                                                if ($index === 0) {
                                                    $badgeClass = 'stop-badge stop-badge-start';
                                                    $icon = "<i class='fas fa-map-marker-alt'></i> ";
                                                } elseif ($index === $totalStops - 1) {
                                                    $badgeClass = 'stop-badge stop-badge-end';
                                                    $icon = "<i class='fas fa-flag-checkered'></i> ";
                                                }

                                                $badgeHtml = "<span class='{$badgeClass}'>{$icon}{$stopName}{$offsetHtml}</span>";
                                                $fullHtml[] = $badgeHtml;

                                                if ($index === 0 || $index === $totalStops - 1 || $index <= 2 || ($index === 3 && $totalStops === 5)) {
                                                    $truncatedHtml[] = $badgeHtml;
                                                } elseif ($index === 3 && $totalStops > 5) {
                                                    $hiddenCount = $totalStops - 4;
                                                    $truncatedHtml[] = "<span class='more-stops'>+{$hiddenCount} stops</span>";
                                                }
                                            }
                                            echo implode(' <i class="fas fa-chevron-right route-arrow"></i> ', $truncatedHtml);
                                            ?>
                                        </div>
                                        <span class="click-hint"><i class="fas fa-hand-pointer"></i> Click to view full
                                            timeline</span>
                                        <div id="full-timeline-<?= $route['route_id'] ?>" style="display:none;">
                                            <h4 style="margin-bottom:15px; color:var(--primary-blue);">
                                                <?= htmlspecialchars($route['route_name']) ?> - Full Route
                                            </h4>
                                            <div class="route-timeline">
                                                <?= implode(' <i class="fas fa-chevron-right route-arrow" style="transform:rotate(90deg); margin-left:10px;"></i> ', $fullHtml) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?= ($route['status'] === 'active') ? '<span style="color:var(--success); font-weight:bold; background:#e8f8f5; padding:4px 8px; border-radius:6px; font-size:0.85rem;">Active</span>' : '<span style="color:#999; background:#f1f1f1; padding:4px 8px; border-radius:6px; font-size:0.85rem;">Inactive</span>' ?>
                                    </td>
                                    <td>
                                        <div class="action-flex">
                                            <a href="edit_route.php?id=<?= $r->id() ?>" class="btn-icon" title="Edit"><i
                                                    class="fas fa-edit"></i></a>
                                            <a href="delete_route.php?id=<?= $r->id() ?>" class="btn-icon danger"
                                                onclick="return confirm('Delete this route?')" title="Delete"><i
                                                    class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="pagination-tableRoutes"></div>
                </div>

                <div id="section-stops" class="card" style="margin-top:40px;">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                        <h3 style="color:var(--primary-blue); margin:0;">Stops</h3>

                        <div class="header-actions-wrapper">
                            <div class="search-container">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchStops" placeholder="Search stops..."
                                    onkeyup="handleSearch('tableStops')">
                            </div>
                            <a href="add_stop.php" class="btn btn-primary btn-header-action">
                                <i class="fas fa-map-marker-alt"></i> Add Stop
                            </a>
                        </div>
                    </div>

                    <table class="styled-table" id="tableStops">
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
                                <tr class="searchable-row">
                                    <td style="font-weight:600; color:#555;"><?= htmlspecialchars($s['stop_id']) ?></td>
                                    <td style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></td>
                                    <td style="font-size:0.85rem; color:#777;">
                                        <i class="fas fa-location-arrow" style="color:var(--primary-blue);"></i>
                                        <?= number_format($s['lat'], 4) ?>, <?= number_format($s['lng'], 4) ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['zone_ids'])):
                                            foreach ($s['zone_ids'] as $zid): ?>
                                                <span
                                                    style="background:#e3f2fd; color:var(--primary-blue); padding:4px 8px; border-radius:6px; font-size:0.75rem; font-weight:600; display:inline-block; margin-bottom:2px;">
                                                    <?= htmlspecialchars($zones[$zid]['name'] ?? $zid) ?>
                                                </span>
                                            <?php endforeach; endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-flex">
                                            <a href="edit_stop.php?id=<?= $id ?>" class="btn-icon" title="Edit"><i
                                                    class="fas fa-edit"></i></a>
                                            <a href="delete_stop.php?id=<?= $id ?>" class="btn-icon danger"
                                                onclick="return confirm('Delete stop?')" title="Delete"><i
                                                    class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="pagination-tableStops"></div>
                </div>

                <div id="section-zones" class="card" style="margin-top:40px;">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                        <h3 style="color:var(--primary-blue); margin:0;">Zones</h3>

                        <div class="header-actions-wrapper">
                            <div class="search-container">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchZones" placeholder="Search zones..."
                                    onkeyup="handleSearch('tableZones')">
                            </div>
                            <a href="add_zone.php" class="btn btn-primary btn-header-action">
                                <i class="fas fa-draw-polygon"></i> Add Zone
                            </a>
                        </div>
                    </div>

                    <table class="styled-table" id="tableZones">
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
                                <tr class="searchable-row">
                                    <td style="font-weight:600; color:#555;"><?= htmlspecialchars($z['zone_id']) ?></td>
                                    <td style="font-weight:600;"><?= htmlspecialchars($z['name']) ?></td>
                                    <td style="color:#666; font-size:0.9rem;">
                                        <?= htmlspecialchars($z['description'] ?? '-') ?>
                                    </td>
                                    <td>
                                        <?= ($z['status'] === 'active') ? '<span style="color:var(--success); font-weight:bold; background:#e8f8f5; padding:4px 8px; border-radius:6px; font-size:0.85rem;">Active</span>' : '<span style="color:#999; background:#f1f1f1; padding:4px 8px; border-radius:6px; font-size:0.85rem;">Inactive</span>' ?>
                                    </td>
                                    <td>
                                        <div class="action-flex">
                                            <a href="edit_zone.php?id=<?= $id ?>" class="btn-icon" title="Edit"><i
                                                    class="fas fa-edit"></i></a>
                                            <a href="delete_zone.php?id=<?= $id ?>" class="btn-icon danger"
                                                onclick="return confirm('Delete zone?')" title="Delete"><i
                                                    class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="pagination-tableZones"></div>
                </div>

            </div>
        </div>
    </div>

    <button id="backToTopBtn" onclick="scrollToTop()" title="Go to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="modal-overlay" id="routeModalOverlay" onclick="closeModal(event)">
        <div class="modal-content" id="routeModalContent">
            <button class="modal-close" onclick="document.getElementById('routeModalOverlay').style.display='none'"><i
                    class="fas fa-times"></i></button>
            <div id="modalBody"></div>
        </div>
    </div>

    <script
        src="https://maps.googleapis.com/maps/api/js?key=<?php echo MAPS_API_KEY; ?>&libraries=marker&callback=initSystemMap&loading=async"
        async defer></script>

    <script>
        /* --- HELPER: CONVEX HULL --- */
        function getConvexHull(points) {
            if (points.length < 3) return points;

            let sorted = points.slice().sort((a, b) => {
                if (a.lng === b.lng) return a.lat - b.lat;
                return a.lng - b.lng;
            });

            function cross(o, a, b) {
                return (a.lng - o.lng) * (b.lat - o.lat) - (a.lat - o.lat) * (b.lng - o.lng);
            }

            let lower = [];
            for (let p of sorted) {
                while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) {
                    lower.pop();
                }
                lower.push(p);
            }

            let upper = [];
            for (let i = sorted.length - 1; i >= 0; i--) {
                let p = sorted[i];
                while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) {
                    upper.pop();
                }
                upper.push(p);
            }

            upper.pop();
            lower.pop();
            return lower.concat(upper);
        }

        /* --- HELPER: INFLATE POLYGON --- */
        function inflatePolygon(points, scaleFactor) {
            if (points.length === 0) return points;

            let centroidLat = 0.0;
            let centroidLng = 0.0;

            points.forEach(p => {
                centroidLat += p.lat;
                centroidLng += p.lng;
            });

            centroidLat /= points.length;
            centroidLng /= points.length;

            return points.map(p => {
                return {
                    lat: centroidLat + (p.lat - centroidLat) * scaleFactor,
                    lng: centroidLng + (p.lng - centroidLng) * scaleFactor
                };
            });
        }

        /* --- SYSTEM MAP LOGIC --- */
        function initSystemMap() {
            const stopsData = <?php echo json_encode($stops); ?>;
            const zonesData = <?php echo json_encode($zones); ?>;

            // UPDATED: Added mapId for Advanced Markers support
            const map = new google.maps.Map(document.getElementById('systemMap'), {
                zoom: 12,
                center: { lat: 3.1390, lng: 101.6869 },
                mapId: "DEMO_MAP_ID", // Required for AdvancedMarkerElement
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true
            });

            const bounds = new google.maps.LatLngBounds();
            const infoWindow = new google.maps.InfoWindow();
            const legendDiv = document.getElementById('mapLegend');

            const palette = ['#3498db', '#e74c3c', '#2ecc71', '#9b59b6', '#f39c12', '#1abc9c', '#e67e22', '#34495e', '#d35400', '#c0392b'];
            const zoneColors = {};
            let colorIdx = 0;

            Object.keys(zonesData).forEach(zid => {
                const color = palette[colorIdx % palette.length];
                zoneColors[zid] = color;
                colorIdx++;

                const legendItem = document.createElement('div');
                legendItem.style.display = 'flex';
                legendItem.style.alignItems = 'center';
                legendItem.style.gap = '6px';
                legendItem.innerHTML = `<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:${color}; border: 1px solid rgba(0,0,0,0.1);"></span> <span style="font-weight:500; color:#4a5568;">${zonesData[zid].name}</span>`;
                legendDiv.appendChild(legendItem);
            });

            const defaultColor = '#34495e';
            const hubLegend = document.createElement('div');
            hubLegend.style.display = 'flex';
            hubLegend.style.alignItems = 'center';
            hubLegend.style.gap = '6px';
            hubLegend.innerHTML = `<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:${defaultColor}; border: 2px solid #fff; box-shadow: 0 0 2px rgba(0,0,0,0.4);"></span> <span style="font-weight:500; color:#4a5568;">Hub / Unassigned</span>`;
            legendDiv.appendChild(hubLegend);

            let hasValidStops = false;
            const zonePoints = {};

            // Plot Markers & Aggregate coordinates for zones
            Object.values(stopsData).forEach(stop => {
                if (!stop.lat || !stop.lng) return;
                hasValidStops = true;

                const lat = parseFloat(stop.lat);
                const lng = parseFloat(stop.lng);
                const latLng = new google.maps.LatLng(lat, lng);
                bounds.extend(latLng);

                let markerColor = defaultColor;
                let zoneNames = [];

                if (stop.zone_ids && stop.zone_ids.length > 0) {
                    markerColor = zoneColors[stop.zone_ids[0]] || defaultColor;
                    stop.zone_ids.forEach(zid => {
                        if (zonesData[zid]) {
                            zoneNames.push(zonesData[zid].name);
                            // Aggregate points
                            if (!zonePoints[zid]) zonePoints[zid] = [];
                            zonePoints[zid].push({ lat: lat, lng: lng });
                        }
                    });
                }

                // UPDATED: Use AdvancedMarkerElement with a custom HTML div
                const markerElement = document.createElement('div');
                markerElement.style.width = '16px';
                markerElement.style.height = '16px';
                markerElement.style.backgroundColor = markerColor;
                markerElement.style.border = '2px solid #ffffff';
                markerElement.style.borderRadius = '50%';
                markerElement.style.opacity = '0.9';
                markerElement.style.boxShadow = '0 2px 4px rgba(0,0,0,0.3)';

                const marker = new google.maps.marker.AdvancedMarkerElement({
                    position: latLng,
                    map: map,
                    title: stop.name,
                    content: markerElement,
                    zIndex: 2 // Keep markers above polygons
                });

                // UPDATED: Use 'gmp-click' event listener
                marker.addEventListener('gmp-click', () => {
                    const zText = zoneNames.length > 0 ? zoneNames.join(', ') : 'Main Hub / No Zone';
                    infoWindow.setContent(`
                        <div style="padding: 5px; min-width: 150px; font-family: 'Poppins', sans-serif;">
                            <h4 style="margin:0 0 5px 0; color:var(--primary-blue); font-size:14px;">${stop.name}</h4>
                            <p style="margin:0; font-size:12px; color:#666;"><strong>Zones:</strong> ${zText}</p>
                        </div>
                    `);
                    infoWindow.open(map, marker);
                });
            });

            // --- DRAW POLYGONS FOR ZONES ---
            Object.keys(zonePoints).forEach(zid => {
                const points = zonePoints[zid];
                if (points.length >= 3) {
                    const hull = getConvexHull(points);
                    const paddedHull = inflatePolygon(hull, 1.15); // 15% expansion matching Dart code

                    const color = zoneColors[zid] || '#3498db';

                    new google.maps.Polygon({
                        paths: paddedHull,
                        strokeColor: color,
                        strokeOpacity: 0.8,
                        strokeWeight: 2,
                        fillColor: color,
                        fillOpacity: 0.15,
                        zIndex: 1, // Keep under markers
                        map: map
                    });
                }
            });

            if (hasValidStops) {
                map.fitBounds(bounds);
                const listener = google.maps.event.addListener(map, "idle", function () {
                    if (map.getZoom() > 15) map.setZoom(15);
                    google.maps.event.removeListener(listener);
                });
            }
        }

        // --- PAGINATION & SEARCH LOGIC ---
        const ROWS_PER_PAGE = 5;

        document.addEventListener("DOMContentLoaded", () => {
            initTable('tableRoutes');
            initTable('tableStops');
            initTable('tableZones');
        });

        function initTable(tableId) {
            const table = document.getElementById(tableId);
            table.dataset.currentPage = 1;
            renderTable(tableId);
        }

        function handleSearch(tableId) {
            const searchInputId = tableId.replace('table', 'search');
            const query = document.getElementById(searchInputId).value.toLowerCase();
            const rows = document.querySelectorAll(`#${tableId} tbody tr.searchable-row`);

            rows.forEach(row => {
                const rowText = row.innerText.toLowerCase();
                if (rowText.includes(query)) {
                    row.classList.remove('search-hidden');
                } else {
                    row.classList.add('search-hidden');
                }
            });

            const table = document.getElementById(tableId);
            table.dataset.currentPage = 1;

            renderTable(tableId);
        }

        function renderTable(tableId) {
            const table = document.getElementById(tableId);
            const currentPage = parseInt(table.dataset.currentPage);

            const visibleRows = Array.from(document.querySelectorAll(`#${tableId} tbody tr.searchable-row:not(.search-hidden)`));
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / ROWS_PER_PAGE) || 1;

            const startIndex = (currentPage - 1) * ROWS_PER_PAGE;
            const endIndex = startIndex + ROWS_PER_PAGE;

            document.querySelectorAll(`#${tableId} tbody tr.searchable-row`).forEach(row => {
                row.style.display = 'none';
            });

            visibleRows.slice(startIndex, endIndex).forEach(row => {
                row.style.display = '';
            });

            buildPaginationUI(tableId, currentPage, totalPages, totalRows, startIndex, Math.min(endIndex, totalRows));
        }

        function buildPaginationUI(tableId, current, total, totalRows, start, end) {
            const container = document.getElementById(`pagination-${tableId}`);
            if (totalRows === 0) {
                container.innerHTML = `<div class="pagination-container"><span class="pagination-info">No results found.</span></div>`;
                return;
            }

            let html = `
                <div class="pagination-container">
                    <span class="pagination-info">Showing ${start + 1} to ${end} of ${totalRows} entries</span>
                    <div class="pagination-buttons">
                        <button class="page-btn" ${current === 1 ? 'disabled' : ''} onclick="changePage('${tableId}', ${current - 1})"><i class="fas fa-chevron-left"></i></button>
            `;

            for (let i = 1; i <= total; i++) {
                html += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="changePage('${tableId}', ${i})">${i}</button>`;
            }

            html += `
                        <button class="page-btn" ${current === total ? 'disabled' : ''} onclick="changePage('${tableId}', ${current + 1})"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            `;
            container.innerHTML = html;
        }

        function changePage(tableId, targetPage) {
            const table = document.getElementById(tableId);
            table.dataset.currentPage = targetPage;
            renderTable(tableId);
        }

        /* --- MODAL LOGIC --- */
        function openRouteModal(routeId) {
            let hiddenContent = document.getElementById('full-timeline-' + routeId).innerHTML;
            document.getElementById('modalBody').innerHTML = hiddenContent;
            document.getElementById('routeModalOverlay').style.display = 'flex';
        }

        function closeModal(event) {
            if (event.target.id === 'routeModalOverlay') {
                document.getElementById('routeModalOverlay').style.display = 'none';
            }
        }

        /* --- BACK TO TOP BUTTON LOGIC --- */
        const topBtn = document.getElementById("backToTopBtn");
        window.addEventListener("scroll", () => {
            if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) {
                topBtn.classList.add("show");
            } else {
                topBtn.classList.remove("show");
            }
        });

        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>

</html>