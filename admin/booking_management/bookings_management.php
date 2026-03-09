<?php
session_start();
require_once '../../config.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// 2. FETCH HELPERS
$students = [];
foreach ($firestore->database()->collection('Students')->documents() as $s) {
    $students[$s->id()] = $s->data()['full_name'] ?? 'Unknown Student';
}

$drivers = [];
foreach ($firestore->database()->collection('Staffs')->where('role', '=', 'driver')->documents() as $d) {
    $drivers[$d->id()] = $d->data()['full_name'] ?? 'Unknown Driver';
}

$stops = [];
foreach ($firestore->database()->collection('Stops')->documents() as $st) {
    $stops[$st->data()['stop_id']] = $st->data()['name'] ?? $st->id();
}

$zones = [];
foreach ($firestore->database()->collection('Zones')->where('status', '=', 'active')->documents() as $z) {
    $zones[$z->id()] = $z->data()['name'] ?? $z->id();
}

// 3. FILTERS
$filterZone = $_GET['zone'] ?? '';
$filterDate = $_GET['date'] ?? date('Y-m-d'); 
$todayStr   = date('Y-m-d'); // Current Server Date

// 4. FETCH BOOKINGS
$bookingsRef = $firestore->database()->collection('Bookings')->documents();

$activeOnDemand = [];
$upcomingScheduled = [];
$historyList = [];

foreach ($bookingsRef as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $data['id'] = $doc->id();
    
    // --- DATA PREP ---
    $data['student_name'] = $students[$data['user_id'] ?? ''] ?? 'Unknown User';
    $data['driver_name'] = $drivers[$data['driver_id'] ?? ''] ?? 'Unassigned';
    $bookingZone = $data['zone_id'] ?? '';

    // Zone Filter
    if (!empty($filterZone) && $bookingZone !== $filterZone) continue;

    // Time Formatting & Sort Key
    if (isset($data['request_time'])) {
        $ts = strtotime($data['request_time']);
        $data['display_time'] = date('d M, h:i A', $ts);
        $data['sort_time'] = $ts;
    } elseif (isset($data['booking_time'])) {
        $ts = strtotime($data['booking_time']);
        $data['display_time'] = date('d M, h:i A', $ts);
        $data['sort_time'] = $ts;
    } else {
        $data['display_time'] = '-';
        $data['sort_time'] = 0;
    }

    $data['pickup_name'] = $stops[$data['pickup_stop_id'] ?? ''] ?? ($data['pickup_stop_id'] ?? 'Current Loc');
    $data['dropoff_name'] = $stops[$data['dropoff_stop_id'] ?? ''] ?? ($data['dropoff_stop_id'] ?? 'Dest');

    // --- SORTING & ARCHIVING LOGIC ---
    $status = $data['status'] ?? 'pending';
    $type = $data['type'] ?? 'scheduled';
    $bDate = $data['date'] ?? ''; // Scheduled Date

    $isHistory = false;

    // A. Status Check (Completed/Cancelled go to History)
    if (in_array($status, ['cancelled', 'completed', 'archived', 'expired'])) {
        $isHistory = true;
    }

    // B. Date Check (Past items go to History)
    if ($type === 'scheduled') {
        // If scheduled date is before today
        if (!empty($bDate) && $bDate < $todayStr) {
            $isHistory = true;
        }
    } elseif ($type === 'ondemand') {
        // NEW: If request was made before today (Yesterday or older)
        if ($data['sort_time'] > 0) {
            $reqDate = date('Y-m-d', $data['sort_time']);
            if ($reqDate < $todayStr) {
                $isHistory = true;
            }
        }
    }

    // --- ASSIGN TO LISTS ---
    if ($isHistory) {
        $historyList[] = $data;
        continue;
    }

    if ($type === 'ondemand') {
        $activeOnDemand[] = $data;
    } else {
        // For Active Scheduled, apply Date Filter (Default: Today)
        if (empty($filterDate) || $bDate >= $filterDate) {
            $upcomingScheduled[] = $data;
        }
    }
}

// Sort Lists
usort($activeOnDemand, function($a, $b) {
    $statA = ($a['status'] === 'pending') ? 0 : 1;
    $statB = ($b['status'] === 'pending') ? 0 : 1;
    if ($statA !== $statB) return $statA - $statB;
    return $b['sort_time'] - $a['sort_time'];
});

usort($upcomingScheduled, function($a, $b) {
    return strcmp($a['date'] ?? '', $b['date'] ?? '');
});

usort($historyList, function($a, $b) {
    return $b['sort_time'] - $a['sort_time'];
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Management - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        .filter-bar { background: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 25px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; }
        .filter-label { font-size: 0.85rem; font-weight: 600; color: #666; }
        .form-select, .form-input { padding: 10px; border: 1px solid #ddd; border-radius: 6px; width: 100%; font-size: 0.9rem; }
        
        .tab-nav { display: flex; gap: 10px; border-bottom: 2px solid #eee; margin-bottom: 20px; padding-bottom: 0; }
        .tab-btn { background: none; border: none; padding: 12px 20px; font-size: 1rem; color: #888; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; }
        .tab-btn.active { color: var(--primary-blue); border-bottom-color: var(--primary-blue); }
        
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn 0.3s ease; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-pending { background: #FFF3E0; color: #E65100; border: 1px solid #FFE0B2; }
        .badge-confirmed { background: #E8F5E9; color: #2E7D32; border: 1px solid #C8E6C9; }
        .badge-arriving { background: #E3F2FD; color: #1565C0; border: 1px solid #BBDEFB; }
        .badge-onboard { background: #E1F5FE; color: #0288d1; border: 1px solid #B3E5FC; }
        .badge-cancelled { background: #FFEBEE; color: #C62828; border: 1px solid #FFCDD2; }
        .badge-completed { background: #F5F5F5; color: #616161; border: 1px solid #E0E0E0; }
        .badge-expired { background: #EEEEEE; color: #9E9E9E; border: 1px solid #BDBDBD; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            <h2 class="page-title">Booking Dispatch</h2>

            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label class="filter-label">Filter by Zone</label>
                    <select name="zone" class="form-select" onchange="this.form.submit()">
                        <option value="">All Zones</option>
                        <?php foreach($zones as $zid => $zname): ?>
                            <option value="<?= $zid ?>" <?= $filterZone === $zid ? 'selected' : '' ?>><?= htmlspecialchars($zname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">From Date (Scheduled)</label>
                    <input type="date" name="date" class="form-input" value="<?= $filterDate ?>" onchange="this.form.submit()">
                </div>

                <div class="filter-group" style="flex: 0;">
                    <a href="bookings_management.php" class="btn" style="background:#f1f1f1; color:#333; padding:10px 20px; text-decoration:none; white-space:nowrap;">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>

            <?php if (isset($_GET['msg'])): ?>
                <div style="background:#d4edda; color:#155724; padding:12px; border-radius:8px; margin-bottom:20px; border:1px solid #c3e6cb;">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']) ?>
                </div>
            <?php elseif (isset($_GET['err'])): ?>
                <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:8px; margin-bottom:20px; border:1px solid #f5c6cb;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_GET['err']) ?>
                </div>
            <?php endif; ?>

            <div class="tab-nav">
                <button class="tab-btn active" onclick="openTab('active_od')">
                    <i class="fas fa-satellite-dish"></i> Live Requests 
                    <?php if(count($activeOnDemand) > 0): ?>
                        <span style="background:var(--danger); color:white; padding:2px 8px; border-radius:10px; font-size:0.8rem; margin-left:5px;"><?= count($activeOnDemand) ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" onclick="openTab('upcoming_sch')">
                    <i class="far fa-calendar-alt"></i> Upcoming Trips
                </button>
                <button class="tab-btn" onclick="openTab('history')">
                    <i class="fas fa-history"></i> History / Logs
                </button>
            </div>

            <div id="active_od" class="tab-pane active">
                <div class="card">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Student</th>
                                <th>Route / Zone</th>
                                <th>Assigned Driver</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($activeOnDemand)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:40px; color:#999;">No active requests for today.</td></tr>
                            <?php else: ?>
                                <?php foreach($activeOnDemand as $row): 
                                    $statusClass = 'badge-' . strtolower($row['status'] ?? 'pending');
                                ?>
                                <tr>
                                    <td><?= $row['display_time'] ?></td>
                                    <td>
                                        <div style="font-weight:600; color:var(--primary-blue);"><?= htmlspecialchars($row['student_name']) ?></div>
                                        <div style="font-size:0.75rem; color:#888;">ID: <?= htmlspecialchars($row['user_id'] ?? '-') ?></div>
                                    </td>
                                    <td style="font-size:0.9rem;">
                                        <span style="color:green;">From:</span> <?= htmlspecialchars($row['pickup_name']) ?><br>
                                        <span style="color:red;">To:</span> <?= htmlspecialchars($row['dropoff_name']) ?>
                                        <br><small style="color:#aaa;">Zone: <?= htmlspecialchars($row['zone_id'] ?? 'Unknown') ?></small>
                                    </td>
                                    <td>
                                        <?php if(($row['status'] ?? '') === 'pending'): ?>
                                            <form action="assign_driver.php" method="POST" style="display:flex; gap:5px;">
                                                <input type="hidden" name="booking_id" value="<?= $row['id'] ?>">
                                                <select name="driver_id" class="form-select" style="padding:5px; height:35px; width:140px;">
                                                    <option value="">Auto-Assign</option>
                                                    <?php foreach($drivers as $did => $dname): ?>
                                                        <option value="<?= $did ?>"><?= htmlspecialchars($dname) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-primary" style="padding:0 12px;"><i class="fas fa-check"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <i class="fas fa-id-card"></i> <?= htmlspecialchars($row['driver_name']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $statusClass ?>"><?= ucfirst($row['status']) ?></span></td>
                                    <td>
                                        <a href="cancel_booking.php?id=<?= $row['id'] ?>" class="btn danger" style="padding:6px 12px;" onclick="return confirm('Reject Request?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="upcoming_sch" class="tab-pane">
                <div class="card">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Student</th>
                                <th>Route Details</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($upcomingScheduled)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:40px; color:#999;">No scheduled bookings found for this date.</td></tr>
                            <?php else: ?>
                                <?php foreach($upcomingScheduled as $row): 
                                    $statusClass = 'badge-' . strtolower($row['status'] ?? 'confirmed');
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;"><?= htmlspecialchars($row['date'] ?? '') ?></div>
                                        <div style="color:#888; font-size:0.85rem;"><?= htmlspecialchars($row['departure_time'] ?? '') ?></div>
                                    </td>
                                    <td style="font-weight:600;"><?= htmlspecialchars($row['student_name']) ?></td>
                                    <td>Fixed Route</td>
                                    <td style="color:#555;"><i class="fas fa-bus"></i> <?= htmlspecialchars($row['shuttle_id'] ?? 'TBD') ?></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= ucfirst($row['status']) ?></span></td>
                                    <td>
                                        <a href="cancel_booking.php?id=<?= $row['id'] ?>" class="btn danger" style="padding:6px 12px;" onclick="return confirm('Cancel Booking?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="history" class="tab-pane">
                <div class="card">
                    <table class="styled-table" style="opacity:0.8;">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Student</th>
                                <th>Type</th>
                                <th>Driver</th>
                                <th>Final Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($historyList)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:40px; color:#999;">No history records found.</td></tr>
                            <?php else: ?>
                                <?php foreach($historyList as $row): 
                                    $statusClass = 'badge-' . strtolower($row['status'] ?? 'completed');
                                ?>
                                <tr style="background:#fafafa;">
                                    <td><?= $row['display_time'] ?></td>
                                    <td><?= htmlspecialchars($row['student_name']) ?></td>
                                    <td>
                                        <?php if(($row['type']??'')==='ondemand'): ?>
                                            <span style="color:#e67e22;"><i class="fas fa-bolt"></i> On-Demand</span>
                                        <?php else: ?>
                                            <span style="color:#2980b9;"><i class="far fa-calendar"></i> Scheduled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['driver_name']) ?></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= ucfirst($row['status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function openTab(tabName) {
    document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById(tabName).classList.add('active');
    
    const index = tabName === 'active_od' ? 0 : (tabName === 'upcoming_sch' ? 1 : 2);
    document.querySelectorAll('.tab-btn')[index].classList.add('active');
}
</script>

</body>
</html>