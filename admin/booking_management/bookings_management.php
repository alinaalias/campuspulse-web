<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../../config.php';

// Check auth and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

// ── Phase 1: PHP Data Fetching & Triage Logic ──
$todayStr = date('Y-m-d');
$now = time();

$batch = $firestore->bulkWriter();
$updatesCount = 0;

// === LAZY EXECUTION 1: AUTO-ARCHIVE 15-MIN OVERDUE SCHEDULES ===
$activeTodaySchedules = $firestore->collection('Schedules')
    ->where('date', '=', $todayStr)
    ->where('status', 'in', ['published', 'active'])
    ->documents();

foreach ($activeTodaySchedules as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();
    $schedTime = strtotime($d['date'] . ' ' . $d['departure_time']);
    if ($schedTime > 0 && ($now - $schedTime) > 900) {
        $batch->update($doc->reference(), [['path' => 'status', 'value' => 'missed']]);
        $updatesCount++;
    }
}

// === LAZY EXECUTION 2: ESCALATE OVERDUE ON-DEMAND TO ADMIN_REVIEW ===
$pendingBookings = $firestore->collection('Bookings')
    ->where('type', '=', 'ondemand')
    ->where('status', 'in', ['pending', 'searching'])
    ->documents();

foreach ($pendingBookings as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    $reqTime = strtotime($d['request_time'] ?? 'now');
    // If pending for more than 2.5 minutes (150 seconds), escalate to admin_review
    if (($now - $reqTime) > 150) {
        $batch->update($doc->reference(), [['path' => 'status', 'value' => 'admin_review']]);
        $updatesCount++;
    }
}

if ($updatesCount > 0) {
    $batch->flush();
}
// ==============================================================

// Fetch Helpers
$studentsMap = [];
$studentsIdMap = [];
$driversMap = [];
$driversShuttleMap = [];
$zonesMap = [];

$studentsDocs = $firestore->collection('Students')->documents();
foreach ($studentsDocs as $doc) {
    if ($doc->exists()) {
        $studentsMap[$doc->id()] = $doc->data()['full_name'] ?? 'Unknown Student';
        $studentsIdMap[$doc->id()] = $doc->data()['student_id'] ?? $doc->id();
    }
}

$staffsDocs = $firestore->collection('Staffs')->documents();
foreach ($staffsDocs as $doc) {
    if ($doc->exists() && isset($doc->data()['role']) && $doc->data()['role'] === 'driver') {
        $driversMap[$doc->id()] = $doc->data()['full_name'] ?? 'Unknown Driver';
        $driversShuttleMap[$doc->id()] = $doc->data()['assigned_shuttle_id'] ?? '';
    }
}

$zonesDocs = $firestore->collection('Zones')->documents();
foreach ($zonesDocs as $doc) {
    if ($doc->exists()) {
        $zonesMap[$doc->id()] = $doc->data()['name'] ?? $doc->id();
    }
}

$stops = [];
foreach ($firestore->collection('Stops')->documents() as $st) {
    $stops[$st->data()['stop_id']] = $st->data()['name'] ?? $st->id();
}

$liveRadar = [];
$futureManifest = [];
$historyData = [];

// Horizon 1 Data ($liveRadar)
$activeOndemandBookings = $firestore->collection('Bookings')
    ->where('type', '=', 'ondemand')
    ->documents();

foreach ($activeOndemandBookings as $doc) {
    if (!$doc->exists())
        continue;
    $data = $doc->data();
    $data['id'] = $doc->id();

    $data['pickup_name'] = $data['pickup_stop_name'] ?? $stops[$data['pickup_stop_id'] ?? ''] ?? ($data['pickup_stop_id'] ?? 'Current Loc');
    $data['dropoff_name'] = $data['dropoff_stop_name'] ?? $stops[$data['dropoff_stop_id'] ?? ''] ?? ($data['dropoff_stop_id'] ?? 'Dest');

    $status = $data['status'] ?? 'pending';
    // THE FIX: Include admin_review in the live radar tracking
    if (in_array($status, ['pending', 'admin_review', 'searching', 'confirmed', 'arriving', 'onboard'])) {
        $data['is_overdue'] = false;
        
       if ($status === 'pending' || $status === 'searching') {
            $reqTime = strtotime($data['request_time'] ?? 'now');
            if (($now - $reqTime) > 150) {
                $data['is_overdue'] = true;
            }
        } elseif ($status === 'admin_review') {
            $data['is_overdue'] = true; // Always highlight admin reviews
        }
        
        $liveRadar[] = $data;
    }
}

usort($liveRadar, function ($a, $b) {
    $statA = in_array($a['status'], ['pending','searching', 'admin_review']) ? 0 : 1;
    $statB = in_array($b['status'], ['pending','searching', 'admin_review']) ? 0 : 1;
    if ($statA !== $statB)
        return $statA - $statB;
    return strtotime($a['request_time'] ?? '0') - strtotime($b['request_time'] ?? '0');
});

// Horizon 2 & 3 Data
$bookingDocs = $firestore->collection('Bookings')->documents();
foreach ($bookingDocs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $status = strtolower($data['status'] ?? '');
    $type = strtolower($data['type'] ?? '');
    if ($type !== 'scheduled') continue;
    if (!in_array($status, ['completed', 'cancelled', 'missed', 'archived'])) continue;
    $data['source'] = 'booking';
    $data['id'] = $doc->id();
    $historyData[] = $data;
}

$scheduleDocs = $firestore->collection('Schedules')->documents();
foreach ($scheduleDocs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $status = strtolower($data['status'] ?? '');
    if (!in_array($status, ['completed', 'cancelled', 'missed', 'archived'])) continue;
    $data['source'] = 'schedule';
    $data['id'] = $doc->id();
    $historyData[] = $data;
}

$ratingsMap = [];
$ratingsDocs = $firestore->collection('Ratings')->documents();
foreach ($ratingsDocs as $rDoc) {
    if (!$rDoc->exists())
        continue;
    $rd = $rDoc->data();
    $key = $rd['booking_id'] ?? $rd['schedule_id'] ?? null;
    if ($key) {
        $ratingsMap[$key] = $rd['rating'] ?? null;
    }
}

$scheduledBookingHistoryDocs = $firestore->collection('Bookings')->documents();
foreach ($scheduledBookingHistoryDocs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $status = $data['status'] ?? '';
    if (strtolower($data['type'] ?? '') !== 'scheduled') continue;
    if (!in_array($status, ['cancelled', 'missed', 'archived'])) continue;
    $scheduleId = $data['schedule_id'] ?? $doc->id();
    $data['id'] = $doc->id();
    $data['display_type'] = 'Scheduled (Booking)';
    $data['driver_name_resolved'] = $driversMap[$data['driver_id'] ?? ''] ?? '-';
    $data['pickup_name'] = $data['pickup_stop_name'] ?? $stops[$data['pickup_stop_id'] ?? ''] ?? 'Unknown';
    $data['dropoff_name'] = $data['dropoff_stop_name'] ?? $stops[$data['dropoff_stop_id'] ?? ''] ?? 'Unknown';
    $data['total_fare'] = floatval($data['fare'] ?? 0);
    $data['rating'] = $ratingsMap[$scheduleId] ?? null;
    $data['_sort_ts'] = strtotime($data['booking_time'] ?? $data['request_time'] ?? 'now');
    $data['manifest_list'] = [[
        'student_name' => $studentsMap[$data['user_id'] ?? ''] ?? 'Unknown',
        'user_id' => $studentsIdMap[$data['user_id'] ?? ''] ?? $data['user_id'],
        'pickup_stop_name' => $data['pickup_name'],
        'dropoff_stop_name' => $data['dropoff_name'],
        'status' => $status
    ]];
    $historyData[] = $data;
}

$scheduledHistoryDocs = $firestore->collection('Schedules')->documents();
foreach ($scheduledHistoryDocs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $status = $data['status'] ?? '';
    if ($status !== 'completed') continue;
    $data['id'] = $doc->id();
    $data['display_type'] = 'Scheduled';
    $data['driver_name_resolved'] = $driversMap[$data['driver_id'] ?? ''] ?? '-';
    $scheduleId = $data['schedule_id'] ?? $doc->id();
    $totalFare = 0;
    $manifestList = [];
    $schFareDocs = $firestore->collection('Bookings')
        ->where('schedule_id', '=', $scheduleId)
        ->documents();
    foreach ($schFareDocs as $fd) {
        if (!$fd->exists()) continue;
        $fData = $fd->data();
        $totalFare += floatval($fData['fare'] ?? 0);
        $manifestList[] = [
            'student_name' => $studentsMap[$fData['user_id'] ?? ''] ?? 'Unknown',
            'user_id' => $studentsIdMap[$fData['user_id'] ?? ''] ?? ($fData['user_id'] ?? '-'),
            'pickup_stop_name' => $fData['pickup_stop_name'] ?? $stops[$fData['pickup_stop_id'] ?? ''] ?? 'Unknown',
            'dropoff_stop_name' => $fData['dropoff_stop_name'] ?? $stops[$fData['dropoff_stop_id'] ?? ''] ?? 'Unknown',
            'status' => $fData['status'] ?? 'unknown'
        ];
    }
    $data['total_fare'] = $totalFare;
    $data['manifest_list'] = $manifestList;
    $data['rating'] = $ratingsMap[$scheduleId] ?? $ratingsMap[$data['id']] ?? null;
    $historyData[] = $data;
}

$ondemandHistoryDocs = $firestore->collection('Bookings')
    ->where('type', '=', 'ondemand')
    ->limit(60)
    ->documents();
foreach ($ondemandHistoryDocs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $status = $data['status'] ?? '';
    if (!in_array($status, ['completed', 'cancelled', 'missed'])) continue;
    $data['id'] = $doc->id();
    $data['display_type'] = 'On-Demand';
    $uid = $data['user_id'] ?? '';
    $data['student_name_resolved'] = $studentsMap[$uid] ?? 'Unknown';
    $data['student_id_resolved'] = $studentsIdMap[$uid] ?? $uid; 
    $data['driver_name_resolved'] = $driversMap[$data['driver_id'] ?? ''] ?? '-';
    if (empty($data['shuttle_id']) && !empty($data['driver_id'])) {
        $data['shuttle_id'] = $driversShuttleMap[$data['driver_id']] ?? 'Unknown';
    }
    $data['pickup_name'] = $data['pickup_stop_name'] ?? $stops[$data['pickup_stop_id'] ?? ''] ?? 'Current Loc';
    $data['dropoff_name'] = $data['dropoff_stop_name'] ?? $stops[$data['dropoff_stop_id'] ?? ''] ?? 'Dest';
    $data['rating'] = $ratingsMap[$data['id']] ?? null;
    $rawTs = $data['booking_time'] ?? $data['request_time'] ?? null;
    $data['_sort_ts'] = $rawTs ? strtotime($rawTs) : 0;
    $data['manifest_list'] = [[
        'student_name' => $data['student_name_resolved'],
        'user_id' => $data['student_id_resolved'],
        'pickup_stop_name' => $data['pickup_name'],
        'dropoff_stop_name' => $data['dropoff_name'],
        'status' => $data['status']
    ]];
    $historyData[] = $data;
}

usort($historyData, function ($a, $b) {
    return ($b['_sort_ts'] ?? 0) <=> ($a['_sort_ts'] ?? 0);
});

// =======================================================
// CLASH PREVENTION: Fetch Busy Drivers to Filter from List
// =======================================================
$busyDrivers = [];

// Drivers currently doing On-Demand
$activeJobs = $firestore->collection('Bookings')
    ->where('status', 'in', ['confirmed', 'arriving', 'arrived', 'onboard'])
    ->documents();
foreach ($activeJobs as $job) {
    if (!empty($job->data()['driver_id'])) {
        $busyDrivers[] = $job->data()['driver_id'];
    }
}

// Drivers currently doing Scheduled Routes
$activeScheds = $firestore->collection('Schedules')
    ->where('status', '=', 'active')
    ->documents();
foreach ($activeScheds as $sched) {
    if (!empty($sched->data()['driver_id'])) {
        $busyDrivers[] = $sched->data()['driver_id'];
    }
}

$availableDrivers = [];
foreach ($driversMap as $did => $dName) {
    // ONLY append if driver is NOT actively executing a trip
    if (!in_array($did, $busyDrivers)) {
        $vehicle = $driversShuttleMap[$did] ?? 'No Vehicle';
        $availableDrivers[] = ['id' => $did, 'name' => $dName . ' (' . $vehicle . ')'];
    }
}

$pageTitle = "Booking Hub - CampusPulse";
$depth = '../../';
include $depth . 'layout/admin/header.php';
?>

<style>
    body {
        background: #f8fafc;
        font-family: 'Poppins', sans-serif;
        color: #334155;
    }
    .tab-nav {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 24px;
        padding: 0 4px;
    }
    .tab-btn {
        background: none;
        border: none;
        padding: 14px 24px;
        font-size: 0.95rem;
        color: #64748b;
        font-weight: 600;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: 0.2s;
    }
    .tab-btn i {
        margin-right: 8px;
        font-size: 1.1rem;
    }
    .tab-btn:hover {
        color: #0f172a;
    }
    .tab-btn.active {
        color: var(--primary-blue);
        border-bottom-color: var(--primary-blue);
    }
    .tab-pane {
        display: none;
    }
    .tab-pane.active {
        display: block;
        animation: fadeIn 0.2s ease-in-out;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(5px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .dispatch-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 20px;
    }
    .card-empty {
        grid-column: 1 / -1;
        background: #fff;
        padding: 60px 20px;
        border-radius: 12px;
        text-align: center;
        color: #94a3b8;
        font-size: 1.05rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
    }
    .dispatch-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .dispatch-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
    }
    .dc-header {
        padding: 16px 20px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 1px solid #f1f5f9;
        background: #f8fafc;
    }
    .dc-student {
        font-weight: 700;
        color: #0f172a;
        font-size: 1.05rem;
    }
    .dc-time-wrap {
        text-align: right;
    }
    .dc-time {
        font-size: 0.8rem;
        color: #64748b;
        font-weight: 600;
        display: block;
    }
    .sla-warning {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.75rem;
        color: #ef4444;
        font-weight: 700;
        background: #fee2e2;
        padding: 4px 8px;
        border-radius: 6px;
        margin-top: 6px;
    }
    .sla-warning .dot {
        width: 8px;
        height: 8px;
        background: #ef4444;
        border-radius: 50%;
        display: inline-block;
        animation: pulse 1s infinite alternate;
    }
    @keyframes pulse {
        from { opacity: 1; transform: scale(1); }
        to { opacity: 0.5; transform: scale(1.3); }
    }
    .dc-body {
        padding: 20px;
        flex: 1;
    }
    .dc-route {
        margin-bottom: 16px;
        font-size: 0.9rem;
        line-height: 1.6;
    }
    .dc-route .loc {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .dc-route .loc i {
        font-size: 0.8rem;
        width: 14px;
        text-align: center;
    }
    .dc-route .loc-from i {
        color: #10b981;
    }
    .dc-route .loc-to i {
        color: #ef4444;
    }
    .dc-route strong {
        color: #1e293b;
    }
    .dc-fare {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0f172a;
        border-top: 1px dashed #e2e8f0;
        padding-top: 14px;
        margin-top: 14px;
        text-align: right;
    }
    .dc-footer {
        padding: 16px 20px;
        border-top: 1px solid #f1f5f9;
        background: #fff;
    }
    .dc-footer-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .form-force-assign {
        display: flex;
        gap: 8px;
        margin-top: 12px;
    }
    .form-force-assign select {
        flex: 1;
        padding: 8px 12px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        font-family: 'Poppins', sans-serif;
        font-size: 0.85rem;
        background: #f8fafc;
    }
    .btn-assign {
        background: #f59e0b;
        color: #fff;
        border: none;
        padding: 0 16px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
    }
    .btn-assign:hover {
        background: #d97706;
    }
    .table-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .styled-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    .styled-table th {
        background: #f8fafc;
        text-transform: uppercase;
        font-size: 0.72rem;
        letter-spacing: 0.05em;
        font-weight: 700;
        color: #64748b;
        padding: 14px 20px;
        text-align: left;
        border-bottom: 2px solid #e2e8f0;
    }
    .styled-table td {
        padding: 14px 20px;
        color: #334155;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .styled-table tbody tr:nth-child(even) td {
        background: #fafbfc;
    }
    .styled-table tbody tr:hover td {
        background: #f0f7ff;
    }
    .badge {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
    }
    .badge-pending {
        background: #fff7ed;
        color: #ea580c;
        border: 1px solid #ffedd5;
    }
    .badge-admin_review {
        background: #fef2f2;
        color: #ef4444;
        border: 1px solid #fca5a5;
    }
    .badge-completed {
        background: #ecfdf5;
        color: #10b981;
        border: 1px solid #d1fae5;
    }
    .badge-cancelled,
    .badge-missed {
        background: #fef2f2;
        color: #ef4444;
        border: 1px solid #fee2e2;
    }
    .badge-default {
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
    }
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.6);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(2px);
    }
    .modal-content {
        background: #fff;
        width: 90%;
        max-width: 500px;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        max-height: 80vh;
        display: flex;
        flex-direction: column;
    }
    .modal-header {
        padding: 18px 24px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-header h3 {
        margin: 0;
        font-size: 1.1rem;
        color: #0f172a;
    }
    .modal-close {
        background: none;
        border: none;
        font-size: 1.25rem;
        color: #64748b;
        cursor: pointer;
        transition: 0.2s;
    }
    .modal-close:hover {
        color: #ef4444;
    }
    .modal-body {
        padding: 0;
        overflow-y: auto;
        flex: 1;
    }
    .manifest-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    .manifest-table th {
        background: #fff;
        position: sticky;
        top: 0;
        padding: 12px 24px;
        text-align: left;
        font-weight: 600;
        color: #64748b;
        border-bottom: 1px solid #e2e8f0;
    }
    .manifest-table td {
        padding: 12px 24px;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
    }
    .pagination-bar {
        padding: 12px 20px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
        color: #64748b;
    }
    .page-btn {
        padding: 6px 12px;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        cursor: pointer;
        font-family: 'Poppins';
    }
    .page-btn:hover { background: #f1f5f9; }
    .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    #pageIndicator { font-weight: 600; color: #0f172a; padding: 0 10px; }
    .search-bar-wrap { margin-bottom: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .search-input-box { position: relative; flex: 1; min-width: 250px; }
    .search-input-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.95rem; }
    .form-input { height: 42px; padding: 0 16px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: 'Poppins', sans-serif; font-size: 0.9rem; color: #334155; background: #fff; box-sizing: border-box; transition: border-color 0.2s; outline: none; }
    .form-input:focus { border-color: var(--primary-blue); }
    #globalSearch { padding-left: 40px; width: 100%; }
    .vertical-timeline { padding: 20px 24px; }
    .tl-item { position: relative; padding-left: 28px; padding-bottom: 20px; border-left: 2px solid #e2e8f0; }
    .tl-item:last-child { border-left: 2px solid transparent; padding-bottom: 0; }
    .tl-dot { position: absolute; left: -7px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: var(--primary-blue); border: 2px solid #fff; box-shadow: 0 0 0 2px var(--primary-blue); }
    .tl-time { font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; }
    .tl-event { font-size: 0.88rem; color: #1e293b; font-weight: 600; }
    .tl-detail { font-size: 0.75rem; color: #64748b; margin-top: 2px; font-weight: 600; text-transform: uppercase; }
    .btn-logs { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; }
    .btn-logs:hover { background: #e2e8f0; }
</style>

<h2 class="page-title">
    <i class="fas fa-satellite-dish" style="color:var(--primary-blue); margin-right:10px;"></i>
    Booking Hub
</h2>

<?php if (isset($_GET['msg'])): ?>
    <div style="background:#ecfdf5; color:#10b981; padding:14px 20px; border-radius:10px; margin-bottom:24px; border:1px solid #d1fae5; font-weight:500;">
        <i class="fas fa-check-circle" style="margin-right:8px;"></i> <?= htmlspecialchars($_GET['msg']) ?>
    </div>
<?php elseif (isset($_GET['err'])): ?>
    <div style="background:#fef2f2; color:#ef4444; padding:14px 20px; border-radius:10px; margin-bottom:24px; border:1px solid #fee2e2; font-weight:500;">
        <i class="fas fa-exclamation-circle" style="margin-right:8px;"></i>
        <?= htmlspecialchars($_GET['err']) ?>
    </div>
<?php endif; ?>

<div class="search-bar-wrap">
    <div class="search-input-box">
        <i class="fas fa-search"></i>
        <input type="text" id="globalSearch" class="form-input interaction-target"
            placeholder="Search ID, name, route...">
    </div>
    <select id="filterType" class="form-input interaction-target" style="width:160px; flex:none;">
        <option value="">All Types</option>
        <option value="ondemand">On-Demand</option>
        <option value="scheduled">Scheduled</option>
    </select>
    <select id="filterStatus" class="form-input interaction-target" style="width:160px; flex:none;">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="completed">Completed</option>
        <option value="cancelled">Cancelled</option>
        <option value="missed">Missed</option>
        <option value="archived">Archived</option>
    </select>
    <button class="btn btn-view interaction-target" onclick="resetMultiFilter()"
        style="height:42px; padding:0 14px; border-radius:10px; margin:0; box-sizing:border-box;" title="Clear Filters">
        <i class="fas fa-undo" style="margin:0;"></i>
    </button>
</div>

<div class="tab-nav">
    <button class="tab-btn active interaction-target" onclick="switchHorizon('horizon1')">
        <i class="fas fa-bolt"></i> Live Radar
        <?php if (count($liveRadar) > 0): ?>
            <span style="background:#ef4444; color:#fff; padding:2px 8px; border-radius:10px; font-size:0.75rem; margin-left:6px;"><?= count($liveRadar) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn interaction-target" onclick="switchHorizon('horizon2')">
        <i class="far fa-calendar-check"></i> Upcoming Trips
    </button>
    <button class="tab-btn interaction-target" onclick="switchHorizon('horizon3')">
        <i class="fas fa-history"></i> History &amp; Logs
    </button>
</div>

<div id="horizon1" class="tab-pane active">
    <?php if (empty($liveRadar)): ?>
        <div class="card-empty">
            <i class="fas fa-satellite" style="font-size:3rem; color:#cbd5e1; margin-bottom:16px; display:block;"></i>
            Radar clear. No active on-demand requests right now.
        </div>
    <?php else: ?>
        <div class="dispatch-grid">
            <?php foreach ($liveRadar as $req):
                $isPending = in_array($req['status'] ?? 'pending', ['pending', 'admin_review']);
                $badgeClass = 'badge-' . strtolower($req['status']);
                if (!in_array($badgeClass, ['badge-pending', 'badge-admin_review', 'badge-completed', 'badge-cancelled', 'badge-missed'])) {
                    $badgeClass = 'badge-default';
                }
                ?>
                <div class="dispatch-card interaction-target" data-type="ondemand"
                    data-status="<?= strtolower($req['status'] ?? 'pending') ?>">
                    <div class="dc-header">
                        <div class="dc-student">
                            <?= htmlspecialchars($studentsMap[$req['user_id'] ?? ''] ?? 'Unknown Student') ?>
                            <div style="font-size:0.72rem; color:#94a3b8; font-weight:500; margin-top:2px;">
                                ID: <?= htmlspecialchars($studentsIdMap[$req['user_id'] ?? ''] ?? ($req['user_id'] ?? 'N/A')) ?>
                            </div>
                        </div>
                        <div class="dc-time-wrap">
                            <span class="dc-time"><?= date('h:i A', strtotime($req['request_time'] ?? 'now')) ?></span>
                            <?php if (!empty($req['is_overdue'])): ?>
                                <div class="sla-warning interaction-target"><span class="dot"></span> SLA Warning</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dc-body">
                        <div class="dc-route">
                            <div class="loc loc-from"><i class="fas fa-circle"></i>
                                <strong><?= htmlspecialchars($req['pickup_name'] ?? 'N/A') ?></strong>
                            </div>
                            <div style="border-left: 2px dashed #cbd5e1; height: 12px; margin-left: 6px; margin-block: 2px;"></div>
                            <div class="loc loc-to"><i class="fas fa-map-marker-alt"></i>
                                <strong><?= htmlspecialchars($req['dropoff_name'] ?? 'N/A') ?></strong>
                            </div>
                        </div>
                        <div class="dc-fare">RM <?= number_format($req['fare'] ?? 0, 2) ?></div>
                    </div>
                    <div class="dc-footer">
                        <div class="dc-footer-top">
                            <?php if (($req['status'] ?? '') === 'admin_review'): ?>
                                <span class="badge badge-admin_review">Needs Review</span>
                            <?php else: ?>
                                <span class="badge <?= $badgeClass ?>"><?= ucfirst($req['status'] ?? 'Pending') ?></span>
                            <?php endif; ?>
                            
                            <?php if (!$isPending && !empty($req['driver_id'])): ?>
                                <span style="font-size:0.8rem; color:#64748b; font-weight:600;"><i class="fas fa-id-card"></i>
                                    <?= htmlspecialchars($driversMap[$req['driver_id']] ?? 'Driver') ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isPending): ?>
                            <form action="assign_driver.php" method="POST" class="form-force-assign interaction-target">
                                <input type="hidden" name="booking_id" value="<?= $req['id'] ?>">
                                <select name="driver_id" required class="interaction-target">
                                    <option value="" disabled selected>Force Assign to...</option>
                                    <?php if(empty($availableDrivers)): ?>
                                        <option value="" disabled>No idle drivers available</option>
                                    <?php else: ?>
                                        <?php foreach ($availableDrivers as $d): ?>
                                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <button type="submit" class="btn-assign interaction-target"><i class="fas fa-bolt"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="horizon2" class="tab-pane">
    <div class="table-card">
        <table class="styled-table" style="margin:0;">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Route &amp; Zone</th>
                    <th>Vehicle</th>
                    <th>Capacity</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($futureManifest)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:40px; color:#94a3b8;">No future
                            schedules found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($futureManifest as $sch): ?>
                        <tr class="searchable-row" data-type="scheduled"
                            data-status="<?= strtolower($sch['status'] ?? 'published') ?>">
                            <td>
                                <div style="font-weight:600; color:#0f172a;">
                                    <?= htmlspecialchars($sch['date'] ?? '') ?>
                                </div>
                                <div style="color:#64748b; font-size:0.8rem;">
                                    <?= htmlspecialchars($sch['departure_time'] ?? '') ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color:var(--primary-blue);"><?= htmlspecialchars($sch['route_id'] ?? 'Unknown') ?></strong>
                                <div style="font-size:0.77rem; color:#64748b; margin-top:3px;">
                                    <i class="fas fa-map-pin"
                                        style="margin-right:3px;"></i><?= htmlspecialchars($sch['zone_name'] ?? 'N/A') ?>
                                </div>
                            </td>
                            <td><i class="fas fa-bus"
                                    style="color:#cbd5e1; margin-right:6px;"></i><?= htmlspecialchars($sch['shuttle_id'] ?? 'Unknown') ?>
                            </td>
                            <?php
                            $booked = intval($sch['actual_booked_count'] ?? count($sch['manifest_list'] ?? []));
                            $cap = intval($sch['capacity'] ?? 1);
                            if ($cap < 1) $cap = 1;
                            $percent = min(100, ($booked / $cap) * 100);
                            $barColor = $percent >= 100 ? '#ef4444' : ($percent >= 80 ? '#f59e0b' : '#10b981');
                            $isFull = ($percent >= 100);
                            ?>
                            <td>
                                <div style="min-width:110px;">
                                    <div style="font-weight:600; color:#0f172a; font-size:0.85rem; margin-bottom:5px;">
                                        <?= $booked ?> / <?= $cap ?>
                                        <?php if ($isFull): ?><i class="fas fa-lock"
                                                style="color:#ef4444; margin-left:4px; font-size:0.75rem;"></i><?php endif; ?>
                                    </div>
                                    <div style="width:100%; height:7px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                                        <div style="height:100%; width:<?= $percent ?>%; background:<?= $barColor ?>; border-radius:4px; transition:width 0.3s;">
                                        </div>
                                    </div>
                                </div>
                                <span style="display:none;" class="hidden-search-data">
                                    <?php
                                    if (!empty($sch['manifest_list'])) {
                                        foreach ($sch['manifest_list'] as $m) {
                                            echo htmlspecialchars($m['student_name'] . ' ' . $m['student_id'] . ' ' . $m['pickup_stop_name'] . ' ');
                                        }
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-logs interaction-target" onclick="openModal('mod_<?= $sch['id'] ?>')">
                                    <i class="fas fa-users"></i> View Manifest
                                </button>
                                <div class="modal-overlay interaction-target" id="mod_<?= $sch['id'] ?>">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h3>Passenger Manifest</h3>
                                            <button class="modal-close interaction-target"
                                                onclick="closeModal('mod_<?= $sch['id'] ?>')"><i
                                                    class="fas fa-times"></i></button>
                                        </div>
                                        <div class="modal-body">
                                            <table class="manifest-table">
                                                <thead>
                                                    <tr>
                                                        <th>Student Name</th>
                                                        <th>Pickup Location</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($sch['manifest_list'])): ?>
                                                        <tr>
                                                            <td colspan="2" style="text-align:center; padding:30px;">No passengers boarded yet.</td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($sch['manifest_list'] as $stu): ?>
                                                            <tr>
                                                                <td style="font-weight:600;">
                                                                    <?= htmlspecialchars($stu['student_name']) ?>
                                                                    <div style="font-size:0.72rem; color:#94a3b8; margin-top:2px;">
                                                                        ID: <?= htmlspecialchars($stu['student_id'] ?? 'N/A') ?>
                                                                    </div>
                                                                </td>
                                                                <td><?= htmlspecialchars($stu['pickup_stop_name']) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="horizon3" class="tab-pane">
    <div class="table-card">
        <table class="styled-table" id="historyTable" style="margin:0;">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Booking Type</th>
                    <th>Details</th>
                    <th>Driver &amp; Vehicle</th>
                    <th>Final Status</th>
                    <th>Financials &amp; Feedback</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historyData)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;">No history
                            records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historyData as $row):
                        $status = $row['status'] ?? 'completed';
                        $badgeClass = 'badge-' . strtolower($status);
                        if (!in_array($badgeClass, ['badge-completed', 'badge-cancelled', 'badge-missed', 'badge-archived']))
                            $badgeClass = 'badge-default';

                        $isOD = (($row['display_type'] ?? '') === 'On-Demand');

                        if ($isOD) {
                            $dispTime = isset($row['booking_time'])
                                ? date('M d, Y h:i A', strtotime($row['booking_time']))
                                : (isset($row['request_time']) ? date('M d, Y h:i A', strtotime($row['request_time'])) : 'N/A');

                            $studentLabel = htmlspecialchars($row['student_name_resolved'] ?? '-');
                            $studentIdFull = htmlspecialchars($row['student_id_resolved'] ?? '');
                            $details = $studentLabel . '<div style="font-size:0.72rem; color:#94a3b8;">ID: ' . $studentIdFull . '</div>';
                            $detailsSub = 'OD: ' . htmlspecialchars(($row['pickup_name'] ?? '') . ' → ' . ($row['dropoff_name'] ?? ''));

                            $fareDisplay = 'RM ' . number_format(floatval($row['fare'] ?? 0), 2);
                        } else {
                            $dispTime = ($row['date'] ?? 'N/A') . ' ' . ($row['departure_time'] ?? '');
                            $details = htmlspecialchars($row['route_id'] ?? 'Unknown Route');

                            $bookedCount = intval($row['booked_count'] ?? 0);
                            $boardedCount = 0;
                            if (!empty($row['manifest_list'])) {
                                foreach ($row['manifest_list'] as $m) {
                                    if (in_array(strtolower($m['status']), ['onboard', 'completed'])) {
                                        $boardedCount++;
                                    }
                                }
                            }
                            $detailsSub = $bookedCount . ' Booked, ' . $boardedCount . ' Boarded';
                            $fareDisplay = 'RM ' . number_format(floatval($row['total_fare'] ?? 0), 2) . ' <span style="font-size:0.72rem; color:#94a3b8;">(total)</span>';
                        }

                        $ratingVal = $row['rating'] ?? null;
                        if ($ratingVal !== null) {
                            $ratingHtml = '⭐ <strong>' . number_format(floatval($ratingVal), 1) . '</strong>/5';
                        } else {
                            $ratingHtml = '<span style="color:#cbd5e1;">Unrated</span>';
                        }
                        $tripDataJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="history-row searchable-row" data-type="<?= $isOD ? 'ondemand' : 'scheduled' ?>"
                            data-status="<?= strtolower($status) ?>">
                            <td style="color:#64748b; font-size:0.82rem; white-space:nowrap;"><?= $dispTime ?></td>
                            <td>
                                <?php if ($isOD): ?>
                                    <span style="color:#ea580c; font-weight:600; background:#fff7ed; padding:4px 8px; border-radius:6px; font-size:0.8rem;"><i class="fas fa-bolt"></i> On-Demand</span>
                                <?php else: ?>
                                    <span style="color:#2563eb; font-weight:600; background:#eff6ff; padding:4px 8px; border-radius:6px; font-size:0.8rem;"><i class="far fa-calendar"></i> Scheduled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:600; color:#0f172a;"><?= $details ?></div>
                                <div style="font-size:0.77rem; color:#94a3b8; margin-top:2px;"><?= $detailsSub ?></div>
                                <span style="display:none;" class="hidden-search-data">
                                    <?php
                                    if (!$isOD && !empty($row['manifest_list'])) {
                                        foreach ($row['manifest_list'] as $m) {
                                            echo htmlspecialchars($m['student_name'] . ' ' . $m['user_id'] . ' ' . $m['pickup_stop_name'] . ' ');
                                        }
                                    }
                                    ?>
                                </span>
                            </td>
                            <td style="color:#475569;">
                                <div style="font-weight:600; color:#1e293b;"><i class="fas fa-id-card" style="color:#cbd5e1; margin-right:4px;"></i>
                                    <?= htmlspecialchars($row['driver_name_resolved'] ?? '-') ?></div>
                                <div style="font-size:0.77rem; color:#94a3b8; margin-top:2px;"><i class="fas fa-bus" style="margin-right:4px;"></i>
                                    <?= htmlspecialchars($row['shuttle_id'] ?? 'N/A') ?></div>
                            </td>
                            <td>
                                <?php
                                    $statusIcon = 'fa-info-circle';
                                    $statusTooltip = '';
                                    
                                    switch(strtolower($status)) {
                                        case 'completed':
                                            $statusIcon = 'fa-check-circle';
                                            $statusTooltip = 'Success: Trip was fully executed.';
                                            break;
                                        case 'cancelled':
                                            $statusIcon = 'fa-times-circle';
                                            $statusTooltip = 'Aborted: Manually stopped by a user or admin.';
                                            break;
                                        case 'missed':
                                            $statusIcon = 'fa-clock';
                                            $statusTooltip = 'System Failed: Time expired before a driver could execute it.';
                                            break;
                                        case 'archived':
                                            $statusIcon = 'fa-archive';
                                            $statusTooltip = 'Cleaned Up: Old record retained for database history.';
                                            break;
                                    }
                                ?>
                                <span class="badge <?= $badgeClass ?>" title="<?= $statusTooltip ?>" style="cursor: help; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="fas <?= $statusIcon ?>"></i> <?= ucfirst($status) ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap;">
                                <div style="font-weight:700; color:#0f172a; font-size:0.9rem;"><?= $fareDisplay ?></div>
                                <div style="font-size:0.8rem; margin-top:4px;"><?= $ratingHtml ?></div>
                            </td>
                            <td>
                                <button class="btn-logs interaction-target" onclick="openLogsModal(<?= $tripDataJson ?>)">
                                    <i class="fas fa-file-alt"></i> View Trip Logs
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($historyData)): ?>
            <div class="pagination-bar">
                <span id="pageInfo">Loading...</span>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button class="page-btn interaction-target" id="prevBtn" onclick="changePage(-1)"><i
                            class="fas fa-chevron-left"></i></button>
                    <span id="pageIndicator">Page 1</span>
                    <button class="page-btn interaction-target" id="nextBtn" onclick="changePage(1)"><i
                            class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay interaction-target" id="logsModal">
    <div class="modal-content" style="max-width:550px;">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <h3 style="margin:0;"><i class="fas fa-file-alt"
                        style="margin-right:8px; color:var(--primary-blue);"></i>Trip Details</h3>
                <button onclick="downloadTripDetailsCSV()"
                    style="background:#0f172a; color:#fff; border:none; padding:5px 12px; border-radius:6px; font-size:0.78rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px;"
                    title="Download CSV">
                    <i class="fas fa-download"></i> Download Full Report
                </button>
            </div>
            <button class="modal-close interaction-target" onclick="closeModal('logsModal')"><i
                    class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="logsModalBody">
            <div class="vertical-timeline" id="logsTimeline"></div>
        </div>
    </div>
</div>

<script>
    // ── Globals & Tab Logic ──
    function switchHorizon(tabId) {
        document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

        document.getElementById(tabId).classList.add('active');
        const targetBtn = document.querySelector(`.tab-btn[onclick="switchHorizon('${tabId}')"]`);
        if (targetBtn) targetBtn.classList.add('active');

        localStorage.setItem('adminDispatchActiveTab', tabId);
    }

    // ── Modal Logic ──
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    window.onclick = function (event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.style.display = "none";
        }
    }

    // ── Trip Details Modal & Universal CSV Export ──
    let currentTripData = null;

    function openLogsModal(tripData) {
        currentTripData = tripData;
        const logsData = tripData.trip_logs || [];
        const timeline = document.getElementById('logsTimeline');
        timeline.innerHTML = '';

        // NEW: Display Cancellation Reason if it exists
        if ((tripData.status === 'cancelled' || tripData.status === 'Cancelled') && tripData.cancellation_reason) {
            timeline.innerHTML += `
                <div style="background-color: #fef2f2; color: #b91c1c; padding: 12px 16px; border-radius: 8px; border: 1px solid #fca5a5; margin-bottom: 20px; font-size: 0.9rem;">
                    <strong><i class="fas fa-exclamation-triangle" style="margin-right: 6px;"></i>Cancellation Reason:</strong><br>
                    <span style="margin-top:4px; display:block;">${tripData.cancellation_reason}</span>
                </div>
            `;
        }

        if (logsData.length === 0) {
            timeline.innerHTML += '<p style="text-align:center; color:#94a3b8; padding:30px;">No timeline logs recorded for this trip.</p>';
        } else {
            logsData.forEach(log => {
                const rawTs = log.timestamp || log.time || null;
                let timeStr = 'N/A';
                if (rawTs) {
                    try {
                        const d = new Date(rawTs.replace(' ', 'T'));
                        timeStr = d.toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Kuala_Lumpur' })
                            + ', ' + d.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kuala_Lumpur' });
                    } catch (e) { timeStr = rawTs; }
                }

                const detail = log.message || log.detail || 'System log recorded';
                const eventLabel = (log.type || 'system').toUpperCase();

                const item = document.createElement('div');
                item.className = 'tl-item';
                item.innerHTML = `
                    <div class="tl-dot"></div>
                    <div class="tl-time">${timeStr}</div>
                    <div class="tl-event">${detail}</div>
                    <div class="tl-detail">TYPE: ${eventLabel}</div>
                `;
                timeline.appendChild(item);
            });
        }
        document.getElementById('logsModal').style.display = 'flex';
    }

    function downloadTripDetailsCSV() {
        if (!currentTripData) return;
        const td = currentTripData;

        let csv = "TRIP SUMMARY\n";
        csv += `Trip ID,"${td.id}"\n`;
        csv += `Type,"${td.display_type}"\n`;
        csv += `Driver,"${td.driver_name_resolved || '-'}"\n`;
        csv += `Vehicle,"${td.shuttle_id || '-'}"\n`;
        csv += `Final Status,"${(td.status || '').toUpperCase()}"\n`;

        const fare = td.display_type === 'On-Demand' ? (td.fare || 0) : (td.total_fare || 0);
        csv += `Total Fare Generated,"RM ${parseFloat(fare).toFixed(2)}"\n\n`;

        csv += "PASSENGER MANIFEST\n";
        csv += "Student Name,User ID,Pickup Location,Dropoff Location,Ticket Status\n";

        if (td.manifest_list && td.manifest_list.length > 0) {
            td.manifest_list.forEach(m => {
                csv += `"${m.student_name}","${m.user_id}","${m.pickup_stop_name}","${m.dropoff_stop_name || '-'}","${(m.status || '').toUpperCase()}"\n`;
            });
        } else {
            csv += "No passengers recorded.,,,,\n";
        }
        csv += "\n";

        csv += "TIMELINE LOGS\n";
        csv += "Time (MYT),Event Type,Details\n";

        if (td.trip_logs && td.trip_logs.length > 0) {
            td.trip_logs.forEach(log => {
                const rawTs = log.timestamp || log.time || '';
                let timeStr = rawTs;
                if (rawTs) {
                    try {
                        const d = new Date(rawTs.replace(' ', 'T'));
                        timeStr = d.toLocaleString('en-MY', { timeZone: 'Asia/Kuala_Lumpur' });
                    } catch (e) { }
                }
                const type = (log.type || 'system').toUpperCase();
                const msg = (log.message || log.detail || '').replace(/"/g, '""').replace(/\n/g, ' ');
                csv += `"${timeStr}","${type}","${msg}"\n`;
            });
        } else {
            csv += "No timeline logs recorded.,,\n";
        }

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `Trip_Report_${td.id}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // ── Pagination (History & Logs) ──
    const historyRows = Array.from(document.querySelectorAll('.history-row'));
    const rowsPerPage = 15;
    let currentPage = 1;
    let visibleRows = historyRows;

    function showPage(page) {
        if (visibleRows.length === 0) {
            const ind = document.getElementById('pageIndicator');
            const inf = document.getElementById('pageInfo');
            if (ind) ind.innerText = 'Page 1 of 1';
            if (inf) inf.innerText = '0 records';
            historyRows.forEach(r => r.style.display = 'none');
            return;
        }
        const totalPages = Math.ceil(visibleRows.length / rowsPerPage);
        const clampedPage = Math.min(Math.max(page, 1), totalPages);
        currentPage = clampedPage;

        const start = (clampedPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        historyRows.forEach(r => r.style.display = 'none');
        visibleRows.slice(start, end).forEach(r => r.style.display = 'table-row');

        const ind = document.getElementById('pageIndicator');
        const inf = document.getElementById('pageInfo');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        if (ind) ind.innerText = `Page ${clampedPage} of ${totalPages}`;
        if (inf) inf.innerText = `${start + 1}–${Math.min(end, visibleRows.length)} of ${visibleRows.length} records`;
        if (prevBtn) prevBtn.disabled = (clampedPage === 1);
        if (nextBtn) nextBtn.disabled = (clampedPage === totalPages);
    }

    function changePage(delta) {
        showPage(currentPage + delta);
    }

    // ── Multi-Filter Logic ──
    function applyFilters() {
        const term = document.getElementById('globalSearch').value.toLowerCase().trim();
        const typeFilter = document.getElementById('filterType').value.toLowerCase();
        const statusFilter = document.getElementById('filterStatus').value.toLowerCase();

        // Filter Horizon 1
        document.querySelectorAll('.dispatch-card').forEach(card => {
            const cardType = card.getAttribute('data-type') || '';
            const cardStatus = card.getAttribute('data-status') || '';

            const matchTerm = card.textContent.toLowerCase().includes(term);
            const matchType = typeFilter === '' || cardType === typeFilter;
            const matchStatus = statusFilter === '' || cardStatus === statusFilter;

            card.style.display = (matchTerm && matchType && matchStatus) ? 'flex' : 'none';
        });

        // Filter Horizon 2
        document.querySelectorAll('#horizon2 .searchable-row').forEach(row => {
            const rowType = row.getAttribute('data-type') || '';
            const rowStatus = row.getAttribute('data-status') || '';

            const matchTerm = row.textContent.toLowerCase().includes(term);
            const matchType = typeFilter === '' || rowType === typeFilter;
            const matchStatus = statusFilter === '' || rowStatus === statusFilter;

            row.style.display = (matchTerm && matchType && matchStatus) ? 'table-row' : 'none';
        });

        // Filter Horizon 3
        visibleRows = historyRows.filter(row => {
            const rowType = row.getAttribute('data-type') || '';
            const rowStatus = row.getAttribute('data-status') || '';

            const matchTerm = row.textContent.toLowerCase().includes(term);
            const matchType = typeFilter === '' || rowType === typeFilter;
            const matchStatus = statusFilter === '' || rowStatus === statusFilter;

            return matchTerm && matchType && matchStatus;
        });
        currentPage = 1;
        showPage(1);
    }

    function resetMultiFilter() {
        document.getElementById('globalSearch').value = '';
        document.getElementById('filterType').value = '';
        document.getElementById('filterStatus').value = '';
        applyFilters();
    }

    document.getElementById('globalSearch').addEventListener('keyup', applyFilters);
    document.getElementById('filterType').addEventListener('change', applyFilters);
    document.getElementById('filterStatus').addEventListener('change', applyFilters);

    // ── Global Auto-Refresh (30s) & State Preservation ──
    let isInteracting = false;
    document.querySelectorAll('input, select, button, form, .interaction-target, .modal-overlay, .modal-content').forEach(el => {
        el.addEventListener('focus', () => isInteracting = true);
        el.addEventListener('blur', () => isInteracting = false);
        el.addEventListener('mouseover', () => isInteracting = true);
        el.addEventListener('mouseout', () => isInteracting = false);
        el.addEventListener('change', () => isInteracting = true);
    });

    // Save state exactly before the browser refreshes
    window.addEventListener('beforeunload', () => {
        sessionStorage.setItem('cp_scrollPos', window.scrollY);
        sessionStorage.setItem('cp_search', document.getElementById('globalSearch').value);
        sessionStorage.setItem('cp_type', document.getElementById('filterType').value);
        sessionStorage.setItem('cp_status', document.getElementById('filterStatus').value);
    });

    setInterval(() => {
        const anyModalOpen = Array.from(document.querySelectorAll('.modal-overlay')).some(m => m.style.display === 'flex');
        if (!isInteracting && !anyModalOpen) {
            window.location.reload();
        }
    }, 30000);

    // ── Init (Restore State) ──
    document.addEventListener('DOMContentLoaded', () => {
        visibleRows = historyRows;
        
        // Restore Filters
        const savedSearch = sessionStorage.getItem('cp_search');
        if (savedSearch !== null) document.getElementById('globalSearch').value = savedSearch;

        const savedType = sessionStorage.getItem('cp_type');
        if (savedType !== null) document.getElementById('filterType').value = savedType;

        const savedStatus = sessionStorage.getItem('cp_status');
        if (savedStatus !== null) document.getElementById('filterStatus').value = savedStatus;

        applyFilters(); // Apply the restored filters to the tables

        // Restore Scroll Position seamlessly
        const scrollPos = sessionStorage.getItem('cp_scrollPos');
        if (scrollPos) {
            window.scrollTo(0, parseInt(scrollPos));
        }

        const savedTab = localStorage.getItem('adminDispatchActiveTab');
        if (savedTab && document.getElementById(savedTab)) {
            switchHorizon(savedTab);
        }
    });
</script>

<?php include $depth . 'layout/admin/footer.php'; ?>