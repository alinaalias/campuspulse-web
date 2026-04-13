<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$today = date('Y-m-d');

// =================================================================================
// 1. AUTO-ARCHIVE LOGIC (Lazy Execution)
// =================================================================================
$expiredQuery = $firestore->database()->collection('Schedules')
    ->where('date', '<', $today)
    ->limit(50)
    ->documents();

if (!$expiredQuery->isEmpty()) {
    $batch = $firestore->database()->batch();
    $updatesCount = 0;
    foreach ($expiredQuery as $doc) {
        if (($doc->data()['status'] ?? '') !== 'archived') {
            $batch->update($doc->reference(), [['path' => 'status', 'value' => 'archived']]);
            $updatesCount++;
        }
    }
    if ($updatesCount > 0)
        $batch->commit();
}

// =================================================================================
// 2. FETCH HELPERS
// =================================================================================
$zones = $firestore->database()->collection('Zones')->where('status', '=', 'active')->documents();

$driversMap = [];
foreach ($firestore->database()->collection('Staffs')->where('role', '=', 'driver')->documents() as $d) {
    $driversMap[$d->id()] = $d->data()['full_name'] ?? 'Unknown';
}

$routesMap = [];
foreach ($firestore->database()->collection('Routes')->documents() as $r) {
    $routesMap[$r->id()] = $r->data();
}

$stopsMap = [];
foreach ($firestore->database()->collection('Stops')->documents() as $s) {
    $stopsMap[$s->data()['stop_id']] = $s->data()['name'] ?? 'Unknown Stop';
}

// =================================================================================
// 3. FETCH & PRE-PROCESS SCHEDULES
// =================================================================================

// --- RAW FETCH: ACTIVE ---
$activeQuery = $firestore->database()->collection('Schedules')
    ->where('date', '>=', $today)
    ->orderBy('date', 'ASC')->orderBy('departure_time', 'ASC');
try {
    $rawActive = $activeQuery->documents();
} catch (Exception $e) {
    $rawActive = [];
}

// --- RAW FETCH: HISTORY ---
$archiveQuery = $firestore->database()->collection('Schedules')
    ->where('date', '<', $today)
    ->orderBy('date', 'DESC')->orderBy('departure_time', 'ASC');
try {
    $rawArchived = $archiveQuery->documents();
} catch (Exception $e) {
    $rawArchived = [];
}

// --- ROBUST FILTERING & SHUTTLE EXTRACTION ---
$displayActive = [];
$displayArchived = [];
$uniqueShuttles = [];

foreach ($rawActive as $s) {
    $d = $s->data();
    if (($d['status'] ?? '') === 'archived')
        continue;
    $displayActive[] = $s;
    if (!empty($d['shuttle_id']))
        $uniqueShuttles[$d['shuttle_id']] = $d['shuttle_id'];
}

foreach ($rawArchived as $s) {
    $d = $s->data();
    if (($d['status'] ?? '') !== 'archived')
        continue;
    $displayArchived[] = $s;
    if (!empty($d['shuttle_id']))
        $uniqueShuttles[$d['shuttle_id']] = $d['shuttle_id'];
}

sort($uniqueShuttles);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Schedule Management - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
            margin-top: 4px;
        }

        .badge-published {
            background: #e8f5e9;
            color: #27ae60;
        }

        .badge-cancelled {
            background: #ffebee;
            color: #c0392b;
        }

        .badge-archived {
            background: #f1f1f1;
            color: #7f8c8d;
        }

        .time-range {
            font-weight: 700;
            color: var(--primary-blue);
            font-size: 1.05rem;
        }

        .eta-btn {
            font-size: 0.75rem;
            background: #e3f2fd;
            color: var(--primary-blue);
            padding: 4px 10px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: 0.2s;
        }

        .eta-btn:hover {
            background: #bbd6fe;
        }

        /* --- PERFECT ALIGNMENT FILTER ROW --- */
        .section-header-block {
            margin-bottom: 20px;
        }

        .section-title {
            margin: 0 0 15px 0;
            color: var(--primary-blue);
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-row {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            width: 100% !important;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-controls {
            display: flex !important;
            flex-direction: row !important;
            gap: 10px !important;
            align-items: center !important;
        }

        /* Force inputs and buttons to have the EXACT same height and box model */
        .filter-input {
            padding: 0 12px !important;
            height: 38px !important;
            border: 1px solid #cbd5e0 !important;
            border-radius: 6px !important;
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.85rem !important;
            color: #2d3748 !important;
            background: #fff !important;
            margin: 0 !important;
            outline: none !important;
            width: auto !important;
            min-width: 150px !important;
            display: inline-flex !important;
            align-items: center !important;
            box-sizing: border-box !important;
            vertical-align: middle !important;
        }

        .filter-input:focus {
            border-color: var(--primary-blue) !important;
            box-shadow: 0 0 0 2px rgba(0, 102, 255, 0.1) !important;
        }

        .btn-reset {
            height: 38px !important;
            padding: 0 15px !important;
            background: #f1f5f9 !important;
            color: #4a5568 !important;
            border: 1px solid #cbd5e0 !important;
            border-radius: 6px !important;
            cursor: pointer !important;
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.85rem !important;
            transition: 0.2s !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            vertical-align: middle !important;
        }

        .btn-reset:hover {
            background: #e2e8f0 !important;
        }

        .btn.danger {
            height: 38px !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            vertical-align: middle !important;
        }

        .btn.danger:disabled {
            background-color: #e2e8f0 !important;
            border-color: #cbd5e0 !important;
            color: #a0aec0 !important;
            cursor: not-allowed !important;
            box-shadow: none !important;
        }

        table.styled-table tbody tr.searchable-row {
            transition: background-color 0.2s ease;
        }

        table.styled-table tbody tr.searchable-row:hover {
            background-color: #f8f9fa;
        }

        table.styled-table tbody tr.search-hidden {
            display: none !important;
        }

        /* --- MODAL STYLES (Enlarged and perfectly aligned) --- */
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

        /* Make modal wider and cleaner */
        .modal-content {
            background: #fff;
            padding: 35px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content.wide {
            max-width: 850px !important;
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

        /* Force perfect horizontal alignment for the 3 top inputs in the modal */
        .modal-form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            align-items: start;
        }

        .modal-form-grid label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }

        .modal-form-grid input,
        .modal-form-grid select {
            width: 100% !important;
            height: 42px !important;
            padding: 0 12px !important;
            border: 1px solid #cbd5e0 !important;
            border-radius: 6px !important;
            box-sizing: border-box !important;
            margin: 0 !important;
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.9rem !important;
        }

        /* Timeline inside ETA Modal */
        .vertical-timeline {
            display: flex;
            flex-direction: column;
            gap: 15px;
            padding-top: 10px;
        }

        .timeline-step {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .step-time {
            font-weight: 700;
            color: var(--primary-blue);
            width: 55px;
            text-align: right;
            font-size: 0.95rem;
        }

        .step-badge {
            background: #ffffff;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #4a5568;
            flex: 1;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            font-weight: 500;
        }

        .step-badge-start {
            background: #f0fff4;
            border-color: #9ae6b4;
            color: #22543d;
        }

        .step-badge-end {
            background: #fff5f5;
            border-color: #feb2b2;
            color: #742a2a;
        }

        .timeline-line {
            width: 2px;
            height: 20px;
            background: #e2e8f0;
            margin-left: 77px;
            margin-top: -10px;
            margin-bottom: -10px;
        }

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
    </style>
</head>

<body>

    <div class="wrapper">
        <?php $depth = '../../'; ?>
        <?php include '../../layout/sidebar.php'; ?>

        <div id="content">
            <?php include '../../layout/header.php'; ?>

            <div class="main-content">

                <div
                    style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <h2 class="page-title" style="margin: 0;">Schedule Management</h2>
                    <button type="button" class="btn"
                        style="background:var(--accent-yellow); color:#333; font-weight:600; padding: 10px 20px;"
                        onclick="document.getElementById('generateModalOverlay').style.display='flex'">
                        <i class="fas fa-magic"></i> Auto-Generate Schedule
                    </button>
                </div>

                <?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
                    <?php
                    $msg = $_GET['msg'] ?? '';
                    $err = $_GET['err'] ?? '';
                    $displayText = '';

                    switch ($msg) {
                        case 'updated':
                            $displayText = "Schedule updated successfully!";
                            break;
                        case 'deleted':
                            $displayText = "Selected schedules deleted.";
                            break;
                        case 'generated':
                            $displayText = "Schedules auto-generated successfully!";
                            break;
                        case 'archived':
                            $displayText = "History archived successfully.";
                            break;
                        default:
                            $displayText = htmlspecialchars($msg);
                            break;
                    }

                    switch ($err) {
                        case 'notfound':
                            $displayText = "Error: Schedule not found.";
                            break;
                        case 'failed':
                            $displayText = "Action failed. Please try again.";
                            break;
                        case 'select_none':
                            $displayText = "Error: No items selected.";
                            break;
                        default:
                            $displayText = htmlspecialchars($err);
                            break;
                    }
                    ?>
                    <?php if ($displayText): ?>
                        <div
                            style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
                            <i class="fas <?php echo $err ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                            <span><?php echo $displayText; ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="card" style="margin-bottom: 30px;">
                    <form method="POST" action="delete_multiple_schedules.php"
                        onsubmit="return confirm('Delete selected active schedules?')">

                        <div class="section-header-block">
                            <h3 class="section-title" style="color:var(--success);"><i class="fas fa-clock"></i> Active
                                & Upcoming</h3>

                            <div class="filter-row">
                                <div class="filter-controls">
                                    <input type="date" id="filterDateActive" class="filter-input"
                                        onchange="applyFilters('tableActive')" title="Filter by Date">

                                    <select id="filterRouteActive" class="filter-input"
                                        onchange="applyFilters('tableActive')" title="Filter by Route">
                                        <option value="">All Routes</option>
                                        <?php foreach ($routesMap as $rid => $rData): ?>
                                            <option value="<?= $rid ?>"><?= htmlspecialchars($rData['route_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <select id="filterShuttleActive" class="filter-input"
                                        onchange="applyFilters('tableActive')" title="Filter by Shuttle">
                                        <option value="">All Shuttles</option>
                                        <?php foreach ($uniqueShuttles as $shuttle): ?>
                                            <option value="<?= htmlspecialchars($shuttle) ?>">
                                                <?= htmlspecialchars($shuttle) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="button" class="btn-reset" onclick="resetTableFilters('tableActive')">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                </div>

                                <button type="submit" id="deleteBtnActive" class="btn danger" style="gap: 8px;"
                                    disabled>
                                    <i class="fas fa-trash"></i> Delete Selected
                                </button>
                            </div>
                        </div>

                        <table class="styled-table" id="tableActive">
                            <thead>
                                <tr>
                                    <th style="width:40px; text-align:center;"><input type="checkbox"
                                            onclick="toggleAll(this, 'active')"></th>
                                    <th>Date / Status</th>
                                    <th>Route Info</th>
                                    <th>Journey Time</th>
                                    <th>Shuttle / Driver</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($displayActive)): ?>
                                    <tr>
                                        <td colspan="6"
                                            style="text-align:center; padding:30px; color:#999; font-style:italic;">
                                            <i class="far fa-folder-open"
                                                style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                                            No active schedules found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($displayActive as $s):
                                        $d = $s->data();
                                        $status = $d['status'] ?? 'published';
                                        $badgeClass = ($status === 'cancelled') ? 'badge-cancelled' : 'badge-published';
                                        $statusLabel = ucfirst($status);

                                        $route = $routesMap[$d['route_id']] ?? null;
                                        $pickup = ($route && isset($stopsMap[$route['start_stop_id']])) ? $stopsMap[$route['start_stop_id']] : 'Unknown';
                                        $dropoff = ($route && isset($stopsMap[$route['end_stop_id']])) ? $stopsMap[$route['end_stop_id']] : 'Unknown';

                                        $etas = $d['etas'] ?? [];
                                        $startTime = $d['departure_time'] ?? '--:--';
                                        $endTime = $startTime;
                                        $hasEtas = !empty($etas);

                                        if ($hasEtas) {
                                            $startTime = reset($etas);
                                            $endTime = end($etas);
                                        }
                                        ?>
                                        <tr class="searchable-row" data-date="<?= htmlspecialchars($d['date']) ?>"
                                            data-route="<?= htmlspecialchars($d['route_id']) ?>"
                                            data-shuttle="<?= htmlspecialchars($d['shuttle_id']) ?>">
                                            <td style="text-align:center;"><input type="checkbox" name="ids[]"
                                                    value="<?= $s->id() ?>" class="cb-active"></td>
                                            <td style="font-weight:600;">
                                                <?= date('d M Y', strtotime($d['date'])) ?>
                                                <div class="badge <?= $badgeClass ?>"><?= $statusLabel ?></div>
                                            </td>
                                            <td>
                                                <div style="color:var(--primary-blue); font-weight:bold;">
                                                    <?= htmlspecialchars($d['route_id']) ?>
                                                </div>
                                                <div style="font-size:0.85rem; color:#666; font-weight:500;">
                                                    <?= htmlspecialchars($route['route_name'] ?? '-') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="time-range">
                                                    <?= htmlspecialchars($startTime) ?> - <?= htmlspecialchars($endTime) ?>
                                                </div>
                                                <?php if ($hasEtas): ?>
                                                    <button type="button" class="eta-btn"
                                                        onclick="openEtaModal('<?= $s->id() ?>')"><i class="fas fa-list-ul"></i>
                                                        View ETAs</button>
                                                    <div id="eta-modal-<?= $s->id() ?>" style="display:none;">
                                                        <h3 style="color:var(--primary-blue); margin-bottom: 5px;">Schedule ETAs
                                                        </h3>
                                                        <p style="color:#666; font-size:0.85rem; margin-bottom: 15px;">Route:
                                                            <strong><?= htmlspecialchars($route['route_name'] ?? $d['route_id']) ?></strong><br>Date:
                                                            <?= date('d M Y', strtotime($d['date'])) ?>
                                                        </p>
                                                        <div class="vertical-timeline">
                                                            <?php
                                                            $stopCount = count($etas);
                                                            $i = 0;
                                                            foreach ($etas as $stopId => $timeStr):
                                                                $stopName = htmlspecialchars($stopsMap[$stopId] ?? $stopId);
                                                                $badgeClass = 'step-badge';
                                                                $icon = '<i class="fas fa-stop-circle" style="color:#ccc;"></i> ';
                                                                if ($i === 0) {
                                                                    $badgeClass .= ' step-badge-start';
                                                                    $icon = '<i class="fas fa-map-marker-alt"></i> ';
                                                                } elseif ($i === $stopCount - 1) {
                                                                    $badgeClass .= ' step-badge-end';
                                                                    $icon = '<i class="fas fa-flag-checkered"></i> ';
                                                                }
                                                                ?>
                                                                <div class="timeline-step">
                                                                    <div class="step-time"><?= htmlspecialchars($timeStr) ?></div>
                                                                    <div class="<?= $badgeClass ?>"><?= $icon ?><?= $stopName ?></div>
                                                                </div>
                                                                <?php if ($i < $stopCount - 1): ?>
                                                                    <div class="timeline-line"></div><?php endif; ?>
                                                                <?php $i++; endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="font-size:0.8rem; color:#999; margin-top:4px;">No ETA data</div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:0.9rem;">
                                                <div><i class="fas fa-bus"></i> <?= htmlspecialchars($d['shuttle_id']) ?></div>
                                                <div style="color:#666;">
                                                    <i class="fas fa-user"></i>
                                                    <?= (!empty($d['driver_id']) && isset($driversMap[$d['driver_id']])) ? htmlspecialchars($driversMap[$d['driver_id']]) : '<span style="color:red">Unassigned</span>' ?>
                                                </div>
                                            </td>
                                            <td><a href="edit_schedule.php?id=<?= $s->id() ?>" class="btn"
                                                    style="padding:6px 12px; background:#f4f6f9; color:#555;"><i
                                                        class="fas fa-edit"></i></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="pagination-tableActive"></div>
                    </form>
                </div>

                <div class="card" style="background-color: #fcfcfc; border: 1px dashed #ccc;">
                    <form method="POST" action="delete_multiple_schedules.php"
                        onsubmit="return confirm('Permanently delete selected archived schedules?')">

                        <div class="section-header-block">
                            <h3 class="section-title" style="color:#7f8c8d;"><i class="fas fa-archive"></i> Archived
                                History</h3>

                            <div class="filter-row">
                                <div class="filter-controls">
                                    <input type="date" id="filterDateArchived" class="filter-input"
                                        onchange="applyFilters('tableArchived')" title="Filter by Date">

                                    <select id="filterRouteArchived" class="filter-input"
                                        onchange="applyFilters('tableArchived')" title="Filter by Route">
                                        <option value="">All Routes</option>
                                        <?php foreach ($routesMap as $rid => $rData): ?>
                                            <option value="<?= $rid ?>"><?= htmlspecialchars($rData['route_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <select id="filterShuttleArchived" class="filter-input"
                                        onchange="applyFilters('tableArchived')" title="Filter by Shuttle">
                                        <option value="">All Shuttles</option>
                                        <?php foreach ($uniqueShuttles as $shuttle): ?>
                                            <option value="<?= htmlspecialchars($shuttle) ?>">
                                                <?= htmlspecialchars($shuttle) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="button" class="btn-reset"
                                        onclick="resetTableFilters('tableArchived')">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                </div>

                                <button type="submit" id="deleteBtnArchived" class="btn danger" style="gap: 8px;"
                                    disabled>
                                    <i class="fas fa-trash"></i> Delete Selected
                                </button>
                            </div>
                        </div>

                        <table class="styled-table" id="tableArchived" style="opacity: 0.9;">
                            <thead>
                                <tr>
                                    <th style="width:40px; text-align:center;"><input type="checkbox"
                                            onclick="toggleAll(this, 'archived')"></th>
                                    <th>Date</th>
                                    <th>Route Info</th>
                                    <th>Journey Time</th>
                                    <th>Shuttle / Driver</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($displayArchived)): ?>
                                    <tr>
                                        <td colspan="5"
                                            style="text-align:center; padding:30px; color:#aaa; font-style:italic;">
                                            <i class="fas fa-history"
                                                style="font-size:2rem; margin-bottom:10px; display:block; opacity:0.5;"></i>
                                            No archived history found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($displayArchived as $s):
                                        $d = $s->data();
                                        $route = $routesMap[$d['route_id']] ?? null;

                                        $etas = $d['etas'] ?? [];
                                        $startTime = $d['departure_time'] ?? '--:--';
                                        $endTime = $startTime;
                                        if (!empty($etas)) {
                                            $startTime = reset($etas);
                                            $endTime = end($etas);
                                        }
                                        ?>
                                        <tr class="searchable-row" data-date="<?= htmlspecialchars($d['date']) ?>"
                                            data-route="<?= htmlspecialchars($d['route_id']) ?>"
                                            data-shuttle="<?= htmlspecialchars($d['shuttle_id']) ?>"
                                            style="background:#f9f9f9; color:#666;">
                                            <td style="text-align:center;"><input type="checkbox" name="ids[]"
                                                    value="<?= $s->id() ?>" class="cb-archived"></td>
                                            <td>
                                                <?= date('d M Y', strtotime($d['date'])) ?>
                                                <div class="badge badge-archived">Archived</div>
                                            </td>
                                            <td>
                                                <div style="font-weight:bold; color:#555;">
                                                    <?= htmlspecialchars($d['route_id']) ?>
                                                </div>
                                                <div style="font-size:0.85rem; font-weight:500;">
                                                    <?= htmlspecialchars($route['route_name'] ?? '-') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight:600; color:#555;"><?= htmlspecialchars($startTime) ?> -
                                                    <?= htmlspecialchars($endTime) ?>
                                                </div>
                                            </td>
                                            <td style="font-size:0.9rem;">
                                                <div><i class="fas fa-bus"></i> <?= htmlspecialchars($d['shuttle_id']) ?></div>
                                                <div><i class="fas fa-user"></i>
                                                    <?= (!empty($d['driver_id']) && isset($driversMap[$d['driver_id']])) ? htmlspecialchars($driversMap[$d['driver_id']]) : 'Unassigned' ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="pagination-tableArchived"></div>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <div class="modal-overlay" id="etaModalOverlay" onclick="closeModal(event, 'etaModalOverlay')">
        <div class="modal-content">
            <button type="button" class="modal-close"
                onclick="document.getElementById('etaModalOverlay').style.display='none'"><i
                    class="fas fa-times"></i></button>
            <div id="modalBodyData"></div>
        </div>
    </div>

    <div class="modal-overlay" id="generateModalOverlay" onclick="closeModal(event, 'generateModalOverlay')">
        <div class="modal-content wide">
            <button type="button" class="modal-close"
                onclick="document.getElementById('generateModalOverlay').style.display='none'"><i
                    class="fas fa-times"></i></button>

            <h3
                style="color:var(--primary-blue); margin-top:0; margin-bottom:20px; font-size:1.4rem; padding-bottom:10px; border-bottom:1px solid #eee;">
                <i class="fas fa-magic" style="margin-right:8px; color:var(--accent-yellow);"></i> Auto-Generate
                Schedule
            </h3>

            <div class="modal-form-grid">
                <div>
                    <label>Target Date</label>
                    <input type="date" id="scheduleDate">
                </div>
                <div>
                    <label>Select Zone</label>
                    <select id="zoneSelect">
                        <option value="">-- Select Zone --</option>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?= $z->id() ?>"><?= htmlspecialchars($z->data()['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Select Route</label>
                    <select id="routeSelect" disabled>
                        <option value="">-- Select Zone First --</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:25px; display:grid; grid-template-columns: 1fr 1fr; gap:25px;">
                <div>
                    <label style="font-weight:600; display:block; margin-bottom:8px; color:#333;">Assign
                        Shuttles</label>
                    <div id="shuttleSelect"
                        style="border:1px solid #cbd5e0; padding:15px; border-radius:8px; height:150px; overflow-y:auto; background:#f8f9fa;">
                        <em style="color:#a0aec0; font-size:0.9rem;">Select a zone to load available shuttles...</em>
                    </div>
                </div>
                <div>
                    <label style="font-weight:600; display:block; margin-bottom:8px; color:#333;">Settings</label>
                    <div style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #cbd5e0;">
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px; cursor:pointer;">
                            <input type="checkbox" name="peak[]" value="morning" checked>
                            <span style="font-size:0.95rem;">Morning Peak (7:00 AM - 10:00 AM)</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:15px; cursor:pointer;">
                            <input type="checkbox" name="peak[]" value="evening" checked>
                            <span style="font-size:0.95rem;">Evening Peak (5:00 PM - 7:30 PM)</span>
                        </label>

                        <div
                            style="display:flex; align-items:center; gap:10px; border-top: 1px solid #e2e8f0; padding-top:15px;">
                            <span style="font-weight:600; font-size:0.9rem; color:#4a5568;">Dispatch Interval:</span>
                            <select id="interval"
                                style="width:90px; padding:6px 10px; border:1px solid #cbd5e0; border-radius:6px; outline:none; font-family:'Poppins', sans-serif;">
                                <option value="10">10</option>
                                <option value="15" selected>15</option>
                                <option value="20">20</option>
                                <option value="30">30</option>
                            </select>
                            <span style="font-size:0.85rem; color:#718096;">mins</span>
                        </div>
                    </div>
                </div>
            </div>

            <div
                style="margin-top:30px; padding-top:20px; border-top: 1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                <span id="resultMsg" style="font-weight:600; color:#333;"></span>
                <button onclick="generateSchedule()" class="btn btn-primary"
                    style="padding:12px 25px; font-size:1rem; border-radius:8px;">
                    <i class="fas fa-cogs" style="margin-right:8px;"></i> Start Generation
                </button>
            </div>
        </div>
    </div>

    <script src="schedule_management.js"></script>

    <script>
        // --- CHECKBOX & DELETE BUTTON LOGIC ---
        function updateDeleteButtonState(tableType) {
            const checkboxes = document.querySelectorAll(`.cb-${tableType}`);
            const deleteBtn = document.getElementById(`deleteBtn${tableType === 'active' ? 'Active' : 'Archived'}`);

            let isChecked = false;
            for (let cb of checkboxes) {
                if (cb.checked) {
                    isChecked = true;
                    break;
                }
            }
            deleteBtn.disabled = !isChecked;
        }

        function toggleAll(source, type) {
            const className = type === 'active' ? '.cb-active' : '.cb-archived';
            document.querySelectorAll(className).forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') {
                    cb.checked = source.checked;
                }
            });
            updateDeleteButtonState(type);
        }

        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('cb-active')) {
                updateDeleteButtonState('active');
            } else if (e.target.classList.contains('cb-archived')) {
                updateDeleteButtonState('archived');
            }
        });

        document.addEventListener("DOMContentLoaded", () => {
            updateDeleteButtonState('active');
            updateDeleteButtonState('archived');
        });

        // --- MODAL LOGIC ---
        function openEtaModal(scheduleId) {
            const content = document.getElementById('eta-modal-' + scheduleId).innerHTML;
            document.getElementById('modalBodyData').innerHTML = content;
            document.getElementById('etaModalOverlay').style.display = 'flex';
        }

        function closeModal(event, overlayId) {
            if (event.target.id === overlayId) {
                document.getElementById(overlayId).style.display = 'none';
            }
        }

        // --- FILTER & PAGINATION LOGIC ---
        const ROWS_PER_PAGE = 15;

        document.addEventListener("DOMContentLoaded", () => {
            initTable('tableActive');
            initTable('tableArchived');
        });

        function initTable(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            table.dataset.currentPage = 1;
            renderTable(tableId);
        }

        function resetTableFilters(tableId) {
            const suffix = tableId === 'tableActive' ? 'Active' : 'Archived';
            document.getElementById(`filterDate${suffix}`).value = '';
            document.getElementById(`filterRoute${suffix}`).value = '';
            document.getElementById(`filterShuttle${suffix}`).value = '';
            applyFilters(tableId);
        }

        function applyFilters(tableId) {
            const suffix = tableId === 'tableActive' ? 'Active' : 'Archived';
            const dateVal = document.getElementById(`filterDate${suffix}`).value;
            const routeVal = document.getElementById(`filterRoute${suffix}`).value;
            const shuttleVal = document.getElementById(`filterShuttle${suffix}`).value;

            const rows = document.querySelectorAll(`#${tableId} tbody tr.searchable-row`);

            rows.forEach(row => {
                const rowDate = row.dataset.date || "";
                const rowRoute = row.dataset.route || "";
                const rowShuttle = row.dataset.shuttle || "";

                const matchDate = (dateVal === "" || rowDate === dateVal);
                const matchRoute = (routeVal === "" || rowRoute === routeVal);
                const matchShuttle = (shuttleVal === "" || rowShuttle === shuttleVal);

                if (matchDate && matchRoute && matchShuttle) {
                    row.classList.remove('search-hidden');
                } else {
                    row.classList.add('search-hidden');
                    const cb = row.querySelector(`input[type="checkbox"]`);
                    if (cb) cb.checked = false;
                }
            });

            updateDeleteButtonState(tableId === 'tableActive' ? 'active' : 'archived');

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

            // Hide all rows
            document.querySelectorAll(`#${tableId} tbody tr.searchable-row`).forEach(row => {
                row.style.display = 'none';
            });

            // Show current page
            visibleRows.slice(startIndex, endIndex).forEach(row => {
                row.style.display = '';
            });

            buildPaginationUI(tableId, currentPage, totalPages, totalRows, startIndex, Math.min(endIndex, totalRows));
        }

        function buildPaginationUI(tableId, current, total, totalRows, start, end) {
            const container = document.getElementById(`pagination-${tableId}`);
            if (!container) return;

            if (totalRows === 0) {
                container.innerHTML = `<div class="pagination-container"><span class="pagination-info">No results found matching your filters.</span></div>`;
                return;
            }

            let html = `
                <div class="pagination-container">
                    <span class="pagination-info">Showing ${start + 1} to ${end} of ${totalRows} entries</span>
                    <div class="pagination-buttons">
                        <button type="button" class="page-btn" ${current === 1 ? 'disabled' : ''} onclick="changePage('${tableId}', ${current - 1})"><i class="fas fa-chevron-left"></i></button>
            `;

            let startPage = Math.max(1, current - 2);
            let endPage = Math.min(total, startPage + 4);
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }

            for (let i = startPage; i <= endPage; i++) {
                html += `<button type="button" class="page-btn ${i === current ? 'active' : ''}" onclick="changePage('${tableId}', ${i})">${i}</button>`;
            }

            html += `
                        <button type="button" class="page-btn" ${current === total ? 'disabled' : ''} onclick="changePage('${tableId}', ${current + 1})"><i class="fas fa-chevron-right"></i></button>
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
    </script>
</body>

</html>