<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur'); 

require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$today = date('Y-m-d');
$filterDate = $_GET['date'] ?? ''; 
$filterRoute = $_GET['route_id'] ?? '';

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
    if ($updatesCount > 0) $batch->commit();
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
foreach ($firestore->database()->collection('Routes')->documents() as $r) { $routesMap[$r->id()] = $r->data(); }

$stopsMap = [];
foreach ($firestore->database()->collection('Stops')->documents() as $s) { $stopsMap[$s->data()['stop_id']] = $s->data()['name'] ?? 'Unknown Stop'; }

// =================================================================================
// 3. FETCH & PRE-PROCESS SCHEDULES
// =================================================================================

// --- RAW FETCH: ACTIVE ---
$activeQuery = $firestore->database()->collection('Schedules')->where('date', '>=', $today); 
if (!empty($filterDate)) $activeQuery = $activeQuery->where('date', '=', $filterDate);
if (!empty($filterRoute)) $activeQuery = $activeQuery->where('route_id', '=', $filterRoute);
$activeQuery = $activeQuery->orderBy('date', 'ASC')->orderBy('departure_time', 'ASC'); 
try { $rawActive = $activeQuery->documents(); } catch(Exception $e) { $rawActive = []; }

// --- RAW FETCH: HISTORY ---
$archiveQuery = $firestore->database()->collection('Schedules');
if (!empty($filterDate)) {
    $archiveQuery = $archiveQuery->where('date', '=', $filterDate);
} else {
    $archiveQuery = $archiveQuery->where('date', '<', $today);
}
if (!empty($filterRoute)) $archiveQuery = $archiveQuery->where('route_id', '=', $filterRoute);
$archiveQuery = $archiveQuery->orderBy('date', 'DESC')->orderBy('departure_time', 'ASC');
try { $rawArchived = $archiveQuery->documents(); } catch(Exception $e) { $rawArchived = []; }

// --- ROBUST FILTERING: Separate into clean arrays BEFORE HTML ---
$displayActive = [];
$displayArchived = [];

// Filter Active Query Results
foreach ($rawActive as $s) {
    $d = $s->data();
    // Safety: If it's archived, skip it (even if date is today/future)
    if (($d['status'] ?? '') === 'archived') continue;
    $displayActive[] = $s; 
}

// Filter Archived Query Results
foreach ($rawArchived as $s) {
    $d = $s->data();
    // Safety: If it's NOT archived, skip it (even if date is past)
    if (($d['status'] ?? '') !== 'archived') continue;
    $displayArchived[] = $s;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule Management - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        .filter-section {
            background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px;
            display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; /* Aligned bottom */
        }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; margin-top: 4px; }
        
        .badge-published { background: #e8f5e9; color: #27ae60; }
        .badge-cancelled { background: #ffebee; color: #c0392b; }
        .badge-archived  { background: #f1f1f1; color: #7f8c8d; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            
            <h2 class="page-title">Schedule Management</h2>

            <?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
                <?php 
                    $msg = $_GET['msg'] ?? '';
                    $err = $_GET['err'] ?? '';
                    $displayText = '';

                    // Success Messages
                    switch ($msg) {
                        case 'updated': $displayText = "Schedule updated successfully!"; break;
                        case 'deleted': $displayText = "Selected schedules deleted."; break;
                        case 'generated': $displayText = "Schedules auto-generated successfully!"; break;
                        case 'archived': $displayText = "History archived successfully."; break;
                        // Handle custom text passed via URL (e.g. from archive script)
                        default: $displayText = htmlspecialchars($msg); break;
                    }

                    // Error Messages
                    switch ($err) {
                        case 'notfound': $displayText = "Error: Schedule not found."; break;
                        case 'failed': $displayText = "Action failed. Please try again."; break;
                        case 'select_none': $displayText = "Error: No items selected."; break;
                        default: $displayText = htmlspecialchars($err); break;
                    }
                ?>

                <?php if ($displayText): ?>
                    <div style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
                        <i class="fas <?php echo $err ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo $displayText; ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <form method="GET" class="filter-section">
                <div style="flex: 1; min-width: 200px;">
                    <label style="font-weight:600; font-size:0.9rem; color:#666;">Filter by Date</label>
                    <input type="date" name="date" value="<?= $filterDate ?>" class="form-control" style="padding:8px; margin-bottom:0;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="font-weight:600; font-size:0.9rem; color:#666;">Filter by Route</label>
                    <select name="route_id" class="form-control" style="padding:8px; margin-bottom:0;">
                        <option value="">-- All Routes --</option>
                        <?php foreach ($routesMap as $rid => $rData): ?>
                            <option value="<?= $rid ?>" <?= $filterRoute === $rid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rData['route_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary" style="padding:10px 20px;">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="schedules_management.php" class="btn" style="background:#ecf0f1; color:#333; padding:10px 20px; text-decoration:none;">Reset</a>
                </div>
            </form>

            <div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--accent-yellow);">
                <details>
                    <summary style="cursor:pointer; font-weight:600; color:var(--primary-blue); outline:none; list-style:none;">
                         <h3 style="display:inline-block; margin:0; font-size:1.1rem;"><i class="fas fa-magic"></i> Auto-Generate Schedule</h3>
                         <span style="float:right; font-size:0.8rem; color:#666;">(Click to Expand)</span>
                    </summary>
                    <div style="margin-top:20px; padding-top:15px; border-top:1px solid #eee;">
                        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;">
                            <div><label style="font-weight:600;">Target Date</label><input type="date" id="scheduleDate" class="form-control"></div>
                            <div>
                                <label style="font-weight:600; display:block; margin-bottom:5px;">Select Zone</label>
                                <select id="zoneSelect" class="form-control">
                                    <option value="">-- Select Zone --</option>
                                    <?php foreach ($zones as $z): ?><option value="<?= $z->id() ?>"><?= htmlspecialchars($z->data()['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-weight:600;">Select Route</label>
                                <select id="routeSelect" class="form-control" disabled><option value="">-- Select Zone First --</option></select>
                            </div>
                        </div>
                        <div style="margin-top:15px; display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                            <div>
                                <label style="font-weight:600;">Assign Shuttles</label>
                                <div id="shuttleSelect" style="border:1px solid #ccc; padding:10px; border-radius:4px; height:100px; overflow-y:auto; background:#f9f9f9;"><em style="color:#777;">Select a zone to load...</em></div>
                            </div>
                            <div>
                                <label style="font-weight:600;">Settings</label>
                                <div style="background:#f1f1f1; padding:10px; border-radius:4px;">
                                    <label style="margin-right:15px;"><input type="checkbox" name="peak[]" value="morning" checked> Morning (6:30AM-9:00AM)</label>
                                    <label><input type="checkbox" name="peak[]" value="evening" checked> Evening (5:00PM-7:30PM)</label>
                                    <div style="margin-top:10px; display:flex; gap:10px;"><span style="font-size:0.9rem;">Interval:</span><input type="number" id="interval" value="15" min="5" class="form-control" style="width:80px; padding:5px;"></div>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:20px; text-align:right;">
                            <span id="resultMsg" style="margin-right:15px; font-weight:600;"></span>
                            <button onclick="generateSchedule()" class="btn btn-primary">Generate</button>
                        </div>
                    </div>
                </details>
            </div>

            <div class="card" style="margin-bottom: 30px;">
                <form method="POST" action="delete_multiple_schedules.php" onsubmit="return confirm('Delete selected active schedules?')">
                    <div class="section-header">
                        <h3 style="color:var(--success); margin:0;"><i class="fas fa-clock"></i> Active & Upcoming</h3>
                        <button type="submit" class="btn danger" style="padding:6px 12px; font-size:0.9rem;">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                    </div>

                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;"><input type="checkbox" onclick="toggleAll(this, 'active')"></th>
                                <th>Date / Status</th>
                                <th>Route Info</th>
                                <th>Path</th>
                                <th>Time</th>
                                <th>Shuttle / Driver</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($displayActive)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding:30px; color:#999; font-style:italic;">
                                        <i class="far fa-folder-open" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                                        No active schedules found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($displayActive as $s): 
                                    $d = $s->data();
                                    
                                    // Status Logic
                                    $status = $d['status'] ?? 'published';
                                    $badgeClass = ($status === 'cancelled') ? 'badge-cancelled' : 'badge-published';
                                    $statusLabel = ucfirst($status);

                                    $route = $routesMap[$d['route_id']] ?? null;
                                    $pickup = ($route && isset($stopsMap[$route['start_stop_id']])) ? $stopsMap[$route['start_stop_id']] : 'Unknown';
                                    $dropoff = ($route && isset($stopsMap[$route['end_stop_id']])) ? $stopsMap[$route['end_stop_id']] : 'Unknown';
                                ?>
                                <tr>
                                    <td style="text-align:center;"><input type="checkbox" name="ids[]" value="<?= $s->id() ?>" class="cb-active"></td>
                                    <td style="font-weight:600;">
                                        <?= date('d M Y', strtotime($d['date'])) ?>
                                        <div class="badge <?= $badgeClass ?>"><?= $statusLabel ?></div>
                                    </td>
                                    <td>
                                        <div style="color:var(--primary-blue); font-weight:bold;"><?= htmlspecialchars($d['route_id']) ?></div>
                                        <div style="font-size:0.85rem; color:#666;"><?= htmlspecialchars($route['route_name'] ?? '-') ?></div>
                                    </td>
                                    <td style="font-size:0.9rem;"><?= htmlspecialchars($pickup) ?> <i class="fas fa-arrow-right" style="font-size:0.7rem; color:#999;"></i> <?= htmlspecialchars($dropoff) ?></td>
                                    <td style="font-weight:bold; color:var(--accent-hover);"><?= htmlspecialchars($d['departure_time']) ?></td>
                                    <td style="font-size:0.9rem;">
                                        <div><i class="fas fa-bus"></i> <?= htmlspecialchars($d['shuttle_id']) ?></div>
                                        <div style="color:#666;">
                                            <i class="fas fa-user"></i> <?= (!empty($d['driver_id']) && isset($driversMap[$d['driver_id']])) ? htmlspecialchars($driversMap[$d['driver_id']]) : '<span style="color:red">Unassigned</span>' ?>
                                        </div>
                                    </td>
                                    <td><a href="edit_schedule.php?id=<?= $s->id() ?>" class="btn" style="padding:4px 8px;"><i class="fas fa-edit"></i></a></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
            </div>

            <div class="card" style="background-color: #fcfcfc; border: 1px dashed #ccc;">
                <form method="POST" action="delete_multiple_schedules.php" onsubmit="return confirm('Permanently delete selected archived schedules?')">
                    <div class="section-header">
                        <h3 style="color:#7f8c8d; margin:0;"><i class="fas fa-archive"></i> Archived History</h3>
                        <button type="submit" class="btn danger" style="padding:6px 12px; font-size:0.9rem; background:#7f8c8d;">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                    </div>

                    <table class="styled-table" style="opacity: 0.8;">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;"><input type="checkbox" onclick="toggleAll(this, 'archived')"></th>
                                <th>Date</th>
                                <th>Route Info</th>
                                <th>Path</th>
                                <th>Time</th>
                                <th>Shuttle / Driver</th>
                            </tr>
                        </thead>
                        <tbody>
                             <?php if (empty($displayArchived)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:30px; color:#aaa; font-style:italic;">
                                        <i class="fas fa-history" style="font-size:2rem; margin-bottom:10px; display:block; opacity:0.5;"></i>
                                        No archived history found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($displayArchived as $s): 
                                    $d = $s->data();
                                    $route = $routesMap[$d['route_id']] ?? null;
                                    $pickup = ($route && isset($stopsMap[$route['start_stop_id']])) ? $stopsMap[$route['start_stop_id']] : 'Unknown';
                                    $dropoff = ($route && isset($stopsMap[$route['end_stop_id']])) ? $stopsMap[$route['end_stop_id']] : 'Unknown';
                                ?>
                                <tr style="background:#f9f9f9; color:#666;">
                                    <td style="text-align:center;"><input type="checkbox" name="ids[]" value="<?= $s->id() ?>" class="cb-archived"></td>
                                    <td>
                                        <?= date('d M Y', strtotime($d['date'])) ?>
                                        <div class="badge badge-archived">Archived</div>
                                    </td>
                                    <td>
                                        <div style="font-weight:bold;"><?= htmlspecialchars($d['route_id']) ?></div>
                                        <div style="font-size:0.85rem;"><?= htmlspecialchars($route['route_name'] ?? '-') ?></div>
                                    </td>
                                    <td style="font-size:0.9rem;"><?= htmlspecialchars($pickup) ?> <i class="fas fa-arrow-right" style="font-size:0.7rem; color:#ccc;"></i> <?= htmlspecialchars($dropoff) ?></td>
                                    <td><?= htmlspecialchars($d['departure_time']) ?></td>
                                    <td style="font-size:0.9rem;">
                                        <div><i class="fas fa-bus"></i> <?= htmlspecialchars($d['shuttle_id']) ?></div>
                                        <div><i class="fas fa-user"></i> <?= (!empty($d['driver_id']) && isset($driversMap[$d['driver_id']])) ? htmlspecialchars($driversMap[$d['driver_id']]) : 'Unassigned' ?></div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
            </div>

        </div>
    </div>
</div>

<script src="schedule_management.js"></script>
<script>
function toggleAll(source, type) {
    const className = type === 'active' ? '.cb-active' : '.cb-archived';
    document.querySelectorAll(className).forEach(cb => { cb.checked = source.checked; });
}
</script>
</body>
</html>