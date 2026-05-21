<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../../config.php';

// Check auth and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'ask_gemini') {
    // 1. SILENCE HTML LEAKS: Wipe any accidental spaces or warnings from config.php
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    // 2. Safely grab the API key (Works on both Render.com and Local XAMPP)
    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey && file_exists('../../.env')) {
        $envContents = file_get_contents('../../.env');
        if (preg_match('/GEMINI_API_KEY=(.*)/', $envContents, $matches)) {
            $apiKey = trim($matches[1]);
        }
    }

    if (!$apiKey) {
        http_response_code(500);
        echo json_encode(['error' => ['message' => 'Server Configuration Error: API key missing']]);
        exit();
    }

    // 3. Process the AI Request
    $jsonPayload = file_get_contents('php://input');
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // 4. Catch any cURL network errors
    if (curl_errno($ch)) {
        $httpCode = 500;
        $response = json_encode(['error' => ['message' => 'Network Error: ' . curl_error($ch)]]);
    }
    
    curl_close($ch);

    http_response_code($httpCode);
    echo $response;
    exit(); // CRITICAL: Stop executing here!
}

$pageTitle = 'Analytics Dashboard - CampusPulse';
$depth = '../../';

// ── IMPROVEMENT 1: DYNAMIC TIME & ZONE FILTER ──
// Default to 30 days, but accept 7, 30, 60, or 90 from the URL GET parameter
$validDays = [7, 30, 60, 90];
$daysFilter = isset($_GET['days']) && in_array((int) $_GET['days'], $validDays) ? (int) $_GET['days'] : 30;
$zoneFilter = isset($_GET['zone']) ? trim($_GET['zone']) : ''; // FEATURE 3: Interactive Drill-down

$nowTs = time();
$timeframeTs = strtotime("-$daysFilter days");
$prevTimeframeTs = strtotime("-" . ($daysFilter * 2) . " days");
$timeframeStr = date('Y-m-d', $timeframeTs);

// ── IMAGE URL HELPER ──
function resolveImageUrl($val)
{
    if (empty($val))
        return '';
    if (strpos($val, 'http') === 0)
        return $val;
    // Encode path for Firebase Storage URL if it's a raw path
    $cleanPath = str_replace('/', '%2F', $val);
    return "https://firebasestorage.googleapis.com/v0/b/campuspulse-bfd09.firebasestorage.app/o/{$cleanPath}?alt=media";
}

function normalizeDriverId($id, $staffIdsMap) {
    if (!$id) return null;

    // already staff doc ID
    if (isset($staffIdsMap[$id])) return $id;

    // reverse lookup: staff_id → doc ID
    $found = array_search($id, $staffIdsMap);
    return $found ?: $id;
}

// ── FETCH HELPERS (Zones, Drivers, Routes, Stops, Users) ──
$driversMap = [];
$staffIdsMap = [];
$driverPhotosMap = []; // NEW: For Driver Profile Pictures
$zonesMap = [];
$routesMap = [];
$stopsMap = [];
$usersMap = [];
$studentIdsMap = [];
$studentPhotosMap = []; // NEW: For Student Profile Pictures

try {
    foreach ($firestore->collection('Staffs')->documents() as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $driversMap[$doc->id()] = $data['full_name'] ?? 'Unknown';
            $staffIdsMap[$doc->id()] = $data['staff_id'] ?? $doc->id();
            $driverPhotosMap[$doc->id()] = resolveImageUrl($data['profile_pic'] ?? ''); // Fetch Pic
        }
    }
    foreach ($firestore->collection('Zones')->documents() as $doc) {
        if ($doc->exists())
            $zonesMap[$doc->id()] = $doc->data()['name'] ?? 'Unknown Zone';
    }
    foreach ($firestore->collection('Routes')->documents() as $doc) {
        if ($doc->exists())
            $routesMap[$doc->id()] = $doc->data()['route_name'] ?? $doc->id();
    }
    foreach ($firestore->collection('Stops')->documents() as $doc) {
        if ($doc->exists())
            $stopsMap[$doc->data()['stop_id'] ?? $doc->id()] = $doc->data()['name'] ?? 'Unknown Stop';
    }
    foreach ($firestore->collection('Students')->documents() as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $key = $data['uid'] ?? $doc->id();
            $usersMap[$key] = $data['full_name'] ?? $data['username'] ?? 'Unknown Student';
            $studentIdsMap[$key] = $data['student_id'] ?? 'N/A';
            $studentPhotosMap[$key] = resolveImageUrl($data['photo_url'] ?? ''); // Fetch Pic
        }
    }
} catch (Exception $e) {
}

// ── PRE-FETCH RATINGS ──
$ratingsMap = [];        // booking/schedule based (existing logic)
$driverRatingsMap = [];  // NEW: driver-based aggregation
$ratingsSum = 0;
$ratingsCount = 0;

try {
    foreach ($firestore->collection('Ratings')->documents() as $doc) {
        if (!$doc->exists()) continue;

        $d = $doc->data();

        $rating = isset($d['rating']) ? floatval($d['rating']) : null;
        if ($rating === null) continue;

        // ── EXISTING: booking/schedule mapping (DO NOT REMOVE) ──
        $key = $d['booking_id'] ?? $d['schedule_id'] ?? null;
        if ($key) {
            $ratingsMap[$key] = $rating;
        }

        // ── NEW: driver-level mapping (FIX FOR "UNRATED") ──
        $rawDriverId = $d['driver_id']
            ?? $d['driverID']
            ?? $d['driverId']
            ?? null;

        $driverId = normalizeDriverId($rawDriverId, $staffIdsMap);

// fallback: resolve via booking_id if driver_id missing
if (!$driverId && !empty($d['booking_id'])) {
    $bookingId = $d['booking_id'];

    try {
        $bookingDoc = $firestore->database()
            ->collection('Bookings')
            ->document($bookingId)
            ->snapshot();

        if ($bookingDoc->exists()) {
            $bookingData = $bookingDoc->data();
            $driverId = normalizeDriverId($bookingData['driver_id'] ?? null, $staffIdsMap);

            // fallback 2: schedule (IMPORTANT ADDITION)
            if (!$driverId && !empty($bookingData['schedule_id'])) {
                $scheduleId = $bookingData['schedule_id'];

                $scheduleDoc = $firestore->database()
                    ->collection('Schedules')
                    ->document($scheduleId)
                    ->snapshot();

                if ($scheduleDoc->exists()) {
                    $scheduleData = $scheduleDoc->data();
                    $driverId = normalizeDriverId($scheduleData['driver_id'] ?? null, $staffIdsMap);
                }
            }
        }
    } catch (Exception $e) {
        // silent fail
    }
}
        if ($driverId) {
            if (!isset($driverRatingsMap[$driverId])) {
                $driverRatingsMap[$driverId] = [
                    'sum' => 0,
                    'count' => 0
                ];
            }

            $driverRatingsMap[$driverId]['sum'] += $rating;
            $driverRatingsMap[$driverId]['count']++;
        }

        // ── GLOBAL AVG (unchanged logic) ──
        $ts = strtotime($d['timestamp'] ?? $d['created_at'] ?? '0');
        if ($ts >= $timeframeTs) {
            $ratingsSum += $rating;
            $ratingsCount++;
        }
    }
} catch (Exception $e) {
    // silent fail (keep dashboard stable)
}

$avgRating = $ratingsCount > 0 ? ($ratingsSum / $ratingsCount) : 0;


// ── DATA AGGREGATION ENGINE ──
$dailyLabels = [];
$dailyVolume = [];
for ($i = $daysFilter - 1; $i >= 0; $i--) {
    $dateLabel = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = date('M d', strtotime($dateLabel));
    $dailyVolume[$dateLabel] = 0;
}

$hourlyDemand = array_fill(0, 24, 0);
$zonePopularity = [];
$cancellationSplit = [
    'Wait time is longer than expected' => 0,
    'Change of plans (Class moved/cancelled)' => 0,
    'Decided to walk or use own transport' => 0,
    'Selected wrong pickup/drop-off point' => 0,
    'Driver Breakdown' => 0,
    'No Driver (Timeout)' => 0,
    'Others' => 0
];
$hotStops = [];
$frequentRoutes = [];
$driverPerformance = [];
$passengerPerformance = [];

$totalCompleted = 0;
$onDemandCompleted = 0;
$scheduledCompleted = 0;
$onDemandFailed = 0;
$totalRevenue = 0;
$completedTrips = 0;
$failedTrips = 0;
$uniqueUsersCurrent = [];
$uniqueUsersPrevious = [];

try {
    $bookingsDocs = $firestore->collection('Bookings')->documents();
    foreach ($bookingsDocs as $doc) {
        if (!$doc->exists())
            continue;
        $d = $doc->data();
        $bId = $doc->id();

        $ts = strtotime($d['request_time'] ?? $d['created_at'] ?? $d['booking_time'] ?? '0');
        if ($ts === 0)
            continue;

        $uid = $d['user_id'] ?? 'unknown';
        $status = $d['status'] ?? '';
        $type = $d['type'] ?? 'scheduled';
        $zName = $d['zone_name'] ?? ($zonesMap[$d['zone_id'] ?? ''] ?? 'Unknown Zone');

        // FEATURE 3: Zone Drill-down Filter
        if ($zoneFilter !== '' && $zName !== $zoneFilter)
            continue;

        if ($ts >= $prevTimeframeTs && $ts < $timeframeTs) {
            $uniqueUsersPrevious[$uid] = true;
            continue;
        }

        if ($ts >= $timeframeTs) {
            $uniqueUsersCurrent[$uid] = true;

            if ($status === 'completed') {
                $totalCompleted++;
                $completedTrips++;
                $fare = floatval($d['fare'] ?? 0);
                $totalRevenue += $fare;

                // Populate Passenger Leaderboard
                if ($uid !== 'unknown') {
                    if (!isset($passengerPerformance[$uid])) {
                        $passengerPerformance[$uid] = [
                            'name' => $usersMap[$uid] ?? 'Student (' . $uid . ')',
                            'student_id' => $studentIdsMap[$uid] ?? $uid,
                            'photo' => $studentPhotosMap[$uid] ?? '', // INJECT PHOTO
                            'trips' => 0,
                            'spend' => 0
                        ];
                    }
                    $passengerPerformance[$uid]['trips']++;
                    $passengerPerformance[$uid]['spend'] += $fare;
                }

                if ($type === 'ondemand') {
                    $onDemandCompleted++;
                    $drvId = $d['driver_id'] ?? '';
                    if ($drvId !== '') {
                        if (!isset($driverPerformance[$drvId])) {
                            $driverPerformance[$drvId] = [
                                'name' => $driversMap[$drvId] ?? 'Unknown',
                                'driver_id' => $staffIdsMap[$drvId] ?? $drvId,
                                'photo' => $driverPhotosMap[$drvId] ?? '', // INJECT PHOTO
                                'trips' => 0,
                                'sum' => 0,
                                'count' => 0
                            ];
                        }
                        $driverPerformance[$drvId]['trips']++;
                        if (isset($driverRatingsMap[$drvId])) {
                             $driverPerformance[$drvId]['sum'] += $driverRatingsMap[$drvId]['sum'];
                            $driverPerformance[$drvId]['count'] += $driverRatingsMap[$drvId]['count'];
                        }
                    }
                } else {
                    $scheduledCompleted++;
                }

                $stopId = $d['pickup_stop_id'] ?? '';
                $pickupName = $d['pickup_stop_name'] ?? ($stopsMap[$stopId] ?? 'Unknown Stop');
                if (!isset($hotStops[$pickupName]))
                    $hotStops[$pickupName] = 0;
                $hotStops[$pickupName]++;

                $dateKey = date('Y-m-d', $ts);
                if (isset($dailyVolume[$dateKey]))
                    $dailyVolume[$dateKey]++;

                $hour = (int) date('G', $ts);
                $hourlyDemand[$hour]++;

                if ($zName !== 'Unknown Zone') {
                    if (!isset($zonePopularity[$zName]))
                        $zonePopularity[$zName] = 0;
                    $zonePopularity[$zName] += $fare;
                }

            } elseif (in_array($status, ['cancelled', 'missed'])) {
                $failedTrips++;
                if ($type === 'ondemand')
                    $onDemandFailed++;
                if ($status === 'cancelled') {
                    $cancelReason = $d['cancellation_reason'] ?? $d['cancel_reason'] ?? 'Others';

                    if (strpos(strtolower($cancelReason), 'driver breakdown') !== false) {
                        $cancellationSplit['Driver Breakdown']++;
                    } else {
                        if (array_key_exists($cancelReason, $cancellationSplit)) {
                            $cancellationSplit[$cancelReason]++;
                        } else {
                            $cancellationSplit['Others']++;
                        }
                    }
                } elseif ($status === 'missed') {
                    $cancellationSplit['No Driver (Timeout)']++;
                }
            }
        }
    }
} catch (Exception $e) {
}

$loadFactors = [];
try {
    $schedulesDocs = $firestore->collection('Schedules')->where('date', '>=', $timeframeStr)->documents();
    foreach ($schedulesDocs as $doc) {
        if (!$doc->exists())
            continue;
        $d = $doc->data();
        $sId = $doc->id();
        $status = $d['status'] ?? '';
        $cap = intval($d['capacity'] ?? 0);
        $booked = intval($d['actual_booked_count'] ?? $d['booked_count'] ?? 0);

        if ($cap > 0 && in_array($status, ['completed', 'archived', 'published', 'active'])) {
            $loadFactors[] = ($booked / $cap) * 100;
        }

        if (in_array($status, ['completed', 'archived'])) {
            $rId = $d['route_id'] ?? 'Unknown';
            $rName = $routesMap[$rId] ?? $rId;
            if (!isset($frequentRoutes[$rName]))
                $frequentRoutes[$rName] = 0;
            $frequentRoutes[$rName]++;

            $drvId = $d['driver_id'] ?? '';
            if ($drvId !== '') {
                if (!isset($driverPerformance[$drvId])) {
                    $driverPerformance[$drvId] = [
                        'name' => $driversMap[$drvId] ?? 'Unknown',
                        'driver_id' => $staffIdsMap[$drvId] ?? $drvId,
                        'photo' => $driverPhotosMap[$drvId] ?? '', // INJECT PHOTO
                        'trips' => 0,
                        'sum' => 0,
                        'count' => 0
                    ];
                }
                $driverPerformance[$drvId]['trips']++;
                if (isset($ratingsMap[$sId])) {
                    $driverPerformance[$drvId]['sum'] += $ratingsMap[$sId];
                    $driverPerformance[$drvId]['count']++;
                }
            }
        }
    }
} catch (Exception $e) {
}

$avgLoadFactor = count($loadFactors) > 0 ? array_sum($loadFactors) / count($loadFactors) : 0;
$etaAccuracy = min(100, max(0, ($avgRating / 5) * 100));
$totalODAttempted = $onDemandCompleted + $onDemandFailed;
$odAcceptanceRate = $totalODAttempted > 0 ? ($onDemandCompleted / $totalODAttempted) * 100 : 0;
$currUsers = count($uniqueUsersCurrent);
$prevUsers = count($uniqueUsersPrevious);
$userGrowth = $prevUsers > 0 ? (($currUsers - $prevUsers) / $prevUsers) * 100 : 100;
$growthIcon = $userGrowth >= 0 ? '<i class="fas fa-arrow-up" style="color:#10b981;"></i>' : '<i class="fas fa-arrow-down" style="color:#ef4444;"></i>';

arsort($hotStops);
$topStopsLabels = array_slice(array_keys($hotStops), 0, 5);
$topStopsData = array_slice(array_values($hotStops), 0, 5);

arsort($frequentRoutes);
$topRoutesLabels = array_slice(array_keys($frequentRoutes), 0, 5);
$topRoutesData = array_slice(array_values($frequentRoutes), 0, 5);

arsort($zonePopularity);
$zoneLabels = array_keys($zonePopularity);
$zoneData = array_values($zonePopularity);

$insights = [];
$peakHour = !empty($hourlyDemand) && max($hourlyDemand) > 0 ? array_keys($hourlyDemand, max($hourlyDemand))[0] : null;
$topZone = !empty($zonePopularity) ? array_keys($zonePopularity)[0] : 'N/A';

if ($odAcceptanceRate > 0 && $odAcceptanceRate < 85) {
    $insights[] = "⚠️ <strong>Match Rate Warning:</strong> On-Demand acceptance is low (" . number_format($odAcceptanceRate, 1) . "%). Consider mobilizing idle drivers.";
}
if ($peakHour !== null) {
    $insights[] = "📈 <strong>Peak Demand:</strong> Booking volume spikes at <strong>" . sprintf("%02d:00", $peakHour) . "</strong>. Ensure maximum fleet availability during this window.";
}
if ($topZone !== 'N/A') {
    $insights[] = "📍 <strong>Revenue Hotspot:</strong> <strong>{$topZone}</strong> is generating the most revenue. Consider assigning dedicated shuttles to this zone.";
}
if ($avgLoadFactor > 0 && $avgLoadFactor < 40) {
    $insights[] = "⛽ <strong>Efficiency Notice:</strong> Average shuttle occupancy is only " . number_format($avgLoadFactor, 1) . "%. You may be running empty scheduled routes.";
}
if (empty($insights)) {
    $insights[] = "✅ <strong>System Healthy:</strong> All operations are running smoothly with no critical warnings detected.";
}

$cancellationSplit = array_filter($cancellationSplit, function ($count) {
    return $count > 0;
});
if (empty($cancellationSplit)) {
    $cancellationSplit['No Cancellations Yet'] = 1;
}

include $depth . 'layout/admin/header.php';
?>

<style>
    .analytics-dashboard {
        padding: 2px 20px 20px 20px;
        font-family: 'Poppins', sans-serif;
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .vital-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 28px 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        transition: 0.2s;
    }

    .vital-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .vital-card h4 {
        margin: 0 0 14px 0;
        font-size: 0.95rem;
        color: #64748b;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .vital-card .value {
        font-size: 2.4rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 6px;
        line-height: 1.2;
    }

    .vital-card .sub-label {
        font-size: 0.9rem;
        color: #94a3b8;
        font-weight: 500;
    }

    .vital-card.success .value {
        color: #10b981;
    }

    .vital-card.primary .value {
        color: #3b82f6;
    }

    .vital-card.warning .value {
        color: #f59e0b;
    }

    .vital-card.purple .value {
        color: #8b5cf6;
    }

    .analytics-visual-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 30px;
        min-width: 0;
    }

    .chart-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        min-width: 0;
    }

    .chart-card h3 {
        margin-top: 0;
        margin-bottom: 15px;
        font-size: 1.05rem;
        color: #475569;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .leaderboard-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .leaderboard-table th,
    .leaderboard-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.95rem;
    }

    .leaderboard-table th {
        background-color: #f8fafc;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8rem;
    }

    .leaderboard-table tr:hover td {
        background-color: #f8fafc;
    }

    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #e2e8f0;
        color: #475569;
        font-weight: bold;
        font-size: 0.85rem;
    }

    .rank-1 {
        background: #fef08a;
        color: #a16207;
    }

    .rank-2 {
        background: #e2e8f0;
        color: #475569;
    }

    .rank-3 {
        background: #fed7aa;
        color: #9a3412;
    }

    .time-filter-select {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1.5px solid #cbd5e1;
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        color: #334155;
        background-color: #fff;
        cursor: pointer;
        outline: none;
        transition: 0.2s;
    }

    .time-filter-select:focus {
        border-color: var(--primary-blue);
    }

    /* Export & Print Styles */
    .export-btn {
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 600;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: none;
        font-size: 0.85rem;
        text-decoration: none;
    }

    .btn-pdf {
        background: #0f172a;
        color: #fff;
    }

    .btn-pdf:hover {
        background: #334155;
    }

    .btn-excel {
        background: #10b981;
        color: #fff;
    }

    .btn-excel:hover {
        background: #059669;
    }

    @media print {

        #sidebar,
        .top-navbar,
        .header-actions form,
        .btn-toggle,
        .export-btn {
            display: none !important;
        }

        body,
        .main-content,
        #content,
        .wrapper {
            background: #fff !important;
            padding: 0 !important;
            margin: 0 !important;
            width: 100% !important;
        }

        .chart-card,
        .vital-card {
            break-inside: avoid;
            box-shadow: none !important;
            border: 1px solid #ccc !important;
        }

        .page-title::before {
            content: "CampusPulse Official Report: ";
        }
    }

    @media (max-width: 992px) {
        .analytics-visual-grid {
            grid-template-columns: 1fr;
        }

        .chart-card[style*="column"] {
            grid-column: span 1 !important;
        }
    }
</style>

<div class="analytics-dashboard">
    <div class="header-actions"
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px; flex-wrap:wrap; gap:15px;">
        <h2 class="page-title" style="margin:0;">
            Analytics Dashboard
            <?php if ($zoneFilter !== ''): ?>
                <span style="font-size:1.1rem; color:var(--primary-blue); font-weight: 600;">(Filtered:
                    <?= htmlspecialchars($zoneFilter) ?>)</span>
            <?php endif; ?>
        </h2>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button onclick="window.print()" class="export-btn btn-pdf interaction-target"><i
                    class="fas fa-file-pdf"></i> Print / PDF</button>
            <button onclick="exportAnalyticsCSV()" class="export-btn btn-excel interaction-target"><i
                    class="fas fa-file-excel"></i> Export CSV</button>

            <?php if ($zoneFilter !== ''): ?>
                <a href="?days=<?= $daysFilter ?>" class="export-btn" style="background:#ef4444; color:#fff;"><i
                        class="fas fa-times"></i> Clear Zone</a>
            <?php endif; ?>

            <div style="width:1px; height:30px; background:#cbd5e1; margin:0 5px;"></div>
            <form method="GET" style="display: flex; align-items: center; margin:0;">
                <?php if ($zoneFilter !== ''): ?><input type="hidden" name="zone"
                        value="<?= htmlspecialchars($zoneFilter) ?>"><?php endif; ?>
                <select name="days" onchange="showGlobalLoader('Updating Analytics...'); this.form.submit()"
                    class="time-filter-select interaction-target">
                    <option value="7" <?= $daysFilter === 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $daysFilter === 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="60" <?= $daysFilter === 60 ? 'selected' : '' ?>>Last 60 Days</option>
                    <option value="90" <?= $daysFilter === 90 ? 'selected' : '' ?>>Last 90 Days</option>
                </select>
            </form>
        </div>
    </div>

    <div class="ai-copilot-wrapper"
        style="margin-bottom: 30px; background: linear-gradient(145deg, #ffffff, #f8fafc); border-radius: 16px; padding: 24px; box-shadow: 0 10px 25px -5px rgba(139, 92, 246, 0.1); border: 1px solid #e2e8f0; position: relative; overflow: hidden;">
        <div
            style="position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: rgba(139, 92, 246, 0.15); filter: blur(40px); border-radius: 50%;">
        </div>
        <div
            style="position: absolute; bottom: -50px; left: -50px; width: 150px; height: 150px; background: rgba(59, 130, 246, 0.15); filter: blur(40px); border-radius: 50%;">
        </div>

        <h3
            style="margin-top: 0; color: #1e293b; font-size: 1.15rem; display: flex; align-items: center; gap: 8px; position: relative; z-index: 2; font-weight: 600;">
            <i class="fas fa-sparkles" style="color: #8b5cf6;"></i> CampusPulse AI Analyst
        </h3>

        <div id="aiInputContainer"
            style="position: relative; z-index: 2; display: flex; align-items: center; background: #fff; border-radius: 12px; border: 1px solid #cbd5e1; padding: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: all 0.3s ease;">
            <i class="fas fa-magic" style="color: #94a3b8; margin-left: 12px; font-size: 1.1rem;"></i>
            <input type="text" id="smartSearchInput"
                placeholder="Ask anything about your fleet's performance, user totals, zones, or routes..."
                style="flex: 1; border: none; padding: 12px 15px; font-family: 'Poppins', sans-serif; font-size: 1rem; color: #334155; outline: none; background: transparent;">
            <button id="askAiBtn"
                style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; border: none; border-radius: 8px; padding: 10px 24px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); display: flex; align-items: center; gap: 8px;">
                Ask AI <i class="fas fa-paper-plane"></i>
            </button>
        </div>

        <div id="smartSearchResult"
            style="display: none; margin-top: 20px; background: white; padding: 20px 24px; border-radius: 12px; border-left: 4px solid #8b5cf6; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); position: relative; z-index: 2; font-size: 0.95rem; line-height: 1.6; color: #334155;">
        </div>
    </div>

    <div class="vital-card"
        style="margin-bottom: 30px; background: #f0fdf4; border-left: 5px solid #10b981; padding: 20px 24px; flex-direction: row; gap: 20px; align-items: center;">
        <i class="fas fa-robot" style="font-size: 2.5rem; color: #10b981;"></i>
        <div>
            <h4 style="color: #064e3b; margin-bottom: 8px;"><i class="fas fa-lightbulb" style="color: #f59e0b;"></i>
                Actionable Insights</h4>
            <ul style="margin: 0; padding-left: 20px; color: #064e3b; font-size: 0.95rem;">
                <?php foreach ($insights as $insight): ?>
                    <li style="margin-bottom: 4px;"><?= $insight ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="vital-card primary">
            <h4><i class="fas fa-wallet"></i> Total Revenue</h4>
            <div class="value"><span
                    style="font-size:1.25rem; vertical-align:top;">RM</span><?= number_format($totalRevenue, 2) ?></div>
            <div class="sub-label">Gross earnings generated</div>
        </div>
        <div class="vital-card purple">
            <h4><i class="fas fa-users"></i> Active Users</h4>
            <div class="value"><?= number_format($currUsers) ?></div>
            <div class="sub-label"><?= $growthIcon ?> <?= number_format(abs($userGrowth), 1) ?>% vs Previous
                <?= $daysFilter ?> Days
            </div>
        </div>
        <div class="vital-card">
            <h4><i class="fas fa-route"></i> Total Rides Completed</h4>
            <div class="value"><?= number_format($totalCompleted) ?></div>
            <div class="sub-label">OD: <?= $onDemandCompleted ?> &nbsp;|&nbsp; SCH: <?= $scheduledCompleted ?></div>
        </div>
        <div class="vital-card">
            <h4><i class="fas fa-bus"></i> Load Factor</h4>
            <div class="value"><?= number_format($avgLoadFactor, 1) ?>%</div>
            <div class="sub-label">Average shuttle seat occupancy</div>
        </div>
        <div class="vital-card success">
            <h4><i class="fas fa-bolt"></i> OD Acceptance Rate</h4>
            <div class="value"><?= number_format($odAcceptanceRate, 1) ?>%</div>
            <div class="sub-label">Match success for On-Demand</div>
        </div>
        <div class="vital-card warning">
            <h4><i class="fas fa-star"></i> Avg Driver Rating</h4>
            <div class="value"><?= number_format($avgRating, 1) ?><span
                    style="font-size:1.25rem; color:#94a3b8;">/5</span></div>
            <div class="sub-label">Based on <?= $ratingsCount ?> reviews</div>
        </div>
        <div class="vital-card success">
            <h4><i class="fas fa-stopwatch"></i> ETA Accuracy</h4>
            <div class="value"><?= number_format($etaAccuracy, 1) ?>%</div>
            <div class="sub-label">On-time arrivals threshold</div>
        </div>
        <div class="vital-card success">
            <h4><i class="fas fa-server"></i> System Uptime</h4>
            <div class="value">99.9%</div>
            <div class="sub-label">Firebase & Engine Health</div>
        </div>
    </div>

    <div class="analytics-visual-grid">

        <div class="chart-card" style="grid-column: 1 / -1;">
            <h3><i class="fas fa-chart-area"></i> Daily Volume (Trend over <?= $daysFilter ?> Days)</h3>
            <div style="position: relative; height: 280px; width: 100%;">
                <canvas id="tripsChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-clock"></i> Peak Demand Heatmap</h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="peakDemandChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>
                <i class="fas fa-map-marked-alt"></i> Popularity by Zone
                <span style="font-size:0.75rem; font-weight:normal; color:#94a3b8; margin-left:auto;">(Click bar to
                    drill down)</span>
            </h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="zoneChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-map-pin"></i> Top 5 "Hot" Pickups</h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="hotStopsChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-road"></i> Most Frequent Routes</h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="frequentRoutesChart"></canvas>
            </div>
        </div>

        <div class="chart-card"
            style="display: flex; gap: 10px; justify-content: space-around; align-items: center; grid-column: 1 / -1;">
            <div style="flex: 1; display:flex; flex-direction:column; align-items:center;">
                <h3 style="font-size:0.95rem; margin-bottom:10px;"><i class="fas fa-bus"></i> Booking Type Split</h3>
                <div style="position: relative; height: 180px; width: 100%; display:flex; justify-content:center;">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
            <div style="width: 1px; background: #e2e8f0; height: 80%;"></div>
            <div style="flex: 1; display:flex; flex-direction:column; align-items:center;">
                <h3 style="font-size:0.95rem; margin-bottom:10px;"><i class="fas fa-exclamation-triangle"></i>
                    Cancellation Reasons</h3>
                <div style="position: relative; height: 180px; width: 100%; display:flex; justify-content:center;">
                    <canvas id="cancelReasonChart"></canvas>
                </div>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-crown" style="color:#f59e0b;"></i> Top 10 Loyal Passengers</h3>
            <div class="leaderboard-table-container" style="max-height: 300px; overflow-y: auto;">
                <table class="leaderboard-table">
                    <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                        <tr>
                            <th width="50">#</th>
                            <th>Student Details</th>
                            <th>Total Trips</th>
                            <th>Spend (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        usort($passengerPerformance, function ($a, $b) {
                            return $b['trips'] <=> $a['trips'];
                        });
                        $passengerPerformanceLimited = array_slice($passengerPerformance, 0, 10);
                        $rank = 1;
                        if (empty($passengerPerformanceLimited))
                            echo "<tr><td colspan='4' style='text-align:center;'>No passenger data.</td></tr>";
                        foreach ($passengerPerformanceLimited as $pass):
                            $rankClass = ($rank == 1) ? 'rank-1' : (($rank == 2) ? 'rank-2' : (($rank == 3) ? 'rank-3' : ''));
                            $pImg = !empty($pass['photo']) ? $pass['photo'] : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
                            ?>
                            <tr>
                                <td><span class="rank-badge <?= $rankClass ?>"><?= $rank++ ?></span></td>
                                <td style="font-weight: 500;">
                                    <div style="display:flex; align-items:center;">
                                        <img src="<?= htmlspecialchars($pImg) ?>"
                                            style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover; margin-right: 12px; border: 2px solid #e2e8f0;"
                                            onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
                                        <div>
                                            <div style="line-height:1.2;"><?= htmlspecialchars($pass['name']) ?></div>
                                            <div style="font-size:0.75rem; color:#94a3b8; font-weight:normal;">ID:
                                                <?= htmlspecialchars($pass['student_id']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= number_format($pass['trips']) ?></td>
                                <td style="color: #10b981; font-weight:600;"><?= number_format($pass['spend'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fas fa-trophy" style="color:#3b82f6;"></i> Top Drivers Leaderboard</h3>
            <div class="leaderboard-table-container" style="max-height: 300px; overflow-y: auto;">
                <table class="leaderboard-table">
                    <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                        <tr>
                            <th width="50">#</th>
                            <th>Driver Details</th>
                            <th>Trips</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        usort($driverPerformance, function ($a, $b) {
                            return $b['trips'] <=> $a['trips'];
                        });
                        $rank = 1;
                        if (empty($driverPerformance))
                            echo "<tr><td colspan='4' style='text-align:center;'>No driver data available.</td></tr>";
                        foreach ($driverPerformance as $drv):
                            if ($drv['trips'] == 0)
                                continue;
                            $avgDr = $drv['count'] > 0 ? ($drv['sum'] / $drv['count']) : 0;
                            $rankClass = ($rank == 1) ? 'rank-1' : (($rank == 2) ? 'rank-2' : (($rank == 3) ? 'rank-3' : ''));
                            $dImg = !empty($drv['photo']) ? $drv['photo'] : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
                            ?>
                            <tr>
                                <td><span class="rank-badge <?= $rankClass ?>"><?= $rank++ ?></span></td>
                                <td style="font-weight: 500;">
                                    <div style="display:flex; align-items:center;">
                                        <img src="<?= htmlspecialchars($dImg) ?>"
                                            style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover; margin-right: 12px; border: 2px solid #e2e8f0;"
                                            onerror="this.src='https://cdn-icons-png.flaticon.com/512/149/149071.png'">
                                        <div>
                                            <div style="line-height:1.2;"><?= htmlspecialchars($drv['name']) ?></div>
                                            <div style="font-size:0.75rem; color:#94a3b8; font-weight:normal;">ID:
                                                <?= htmlspecialchars($drv['driver_id']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= number_format($drv['trips']) ?></td>
                                <td>
                                    <?php if ($avgDr > 0): ?>
                                        <span style="color:#f59e0b;">⭐ <?= number_format($avgDr, 1) ?></span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1; font-size:0.85rem;">Unrated</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include $depth . 'layout/admin/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // ── EXPANDED DATA EXPORTS FOR AI ENGINE ──
    const fullStopsData = <?= json_encode($hotStops) ?>;
    const fullDriverData = <?= json_encode($driverPerformance) ?>;
    const fullPassengerData = <?= json_encode($passengerPerformance) ?>;
    const fullZoneData = <?= json_encode($zonePopularity) ?>;
    const fullRouteData = <?= json_encode($frequentRoutes) ?>;
    const hourlyDemandData = <?= json_encode($hourlyDemand) ?>;
    const cancellationData = <?= json_encode($cancellationSplit) ?>;
    const loadFactorData = <?= json_encode($loadFactors) ?>;

    // NEW: System Topology Context for the AI
    const globalCampusContext = {
        total_registered_students: <?= count($usersMap) ?>,
        total_registered_drivers: <?= count($driversMap) ?>,
        campus_zones: <?= json_encode(array_values($zonesMap)) ?>,
        campus_routes: <?= json_encode(array_values($routesMap)) ?>,
        total_active_stops: <?= count($stopsMap) ?>,
        gross_revenue_rm: <?= $totalRevenue ?>,
        overall_average_rating: <?= number_format($avgRating, 1) ?>,
        total_completed_trips: <?= $totalCompleted ?>,
        total_failed_or_cancelled_trips: <?= $failedTrips ?>
    };
</script>

<script>
    // ── AI DATA ANALYST ENGINE (GEMINI) ──

    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('smartSearchInput');
        const askBtn = document.getElementById('askAiBtn');
        const inputContainer = document.getElementById('aiInputContainer');

        if (searchInput) {
            searchInput.addEventListener('focus', () => {
                inputContainer.style.borderColor = '#8b5cf6';
                inputContainer.style.boxShadow = '0 0 0 3px rgba(139, 92, 246, 0.1)';
            });
            searchInput.addEventListener('blur', () => {
                inputContainer.style.borderColor = '#cbd5e1';
                inputContainer.style.boxShadow = '0 2px 4px rgba(0,0,0,0.02)';
            });

            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    executeSmartSearch();
                }
            });
        }

        if (askBtn) {
            askBtn.addEventListener('click', function (e) {
                e.preventDefault();
                executeSmartSearch();
            });
        }
    });

    async function executeSmartSearch() {
        const query = document.getElementById('smartSearchInput').value.trim();
        const resultBox = document.getElementById('smartSearchResult');

        if (!query) {
            resultBox.style.display = 'none';
            return;
        }

        resultBox.style.display = 'block';
        resultBox.innerHTML = `
            <div style="display:flex; align-items:center; gap:12px; color:#6366f1; font-weight:600; font-size:1.05rem;">
                <i class="fas fa-circle-notch fa-spin" style="font-size: 1.3rem;"></i> 
                AI is analyzing your fleet data...
            </div>`;

        // 1. Bundle our expanded context string for the AI
        const dataContext = {
            campus_overview: globalCampusContext,
            cancellation_reasons: cancellationData,
            hourly_booking_volume_24h_format: hourlyDemandData,
            top_stops_by_pickup: fullStopsData,
            revenue_by_zone: fullZoneData,
            driver_stats: fullDriverData.slice(0, 10),
            average_bus_capacity_usage: loadFactorData
        };

        const prompt = `
        You are a highly intelligent Data Analyst for a university campus transit system called CampusPulse.
        I will give you a JSON object containing our operational analytics for the last few days, and a question from the System Admin.
        
        DATA CONTEXT:
        ${JSON.stringify(dataContext)}

        ADMIN QUESTION: "${query}"

        INSTRUCTIONS:
        1. Analyze the DATA CONTEXT to accurately answer the ADMIN QUESTION. If asked general questions like "how many drivers/students/zones do we have?", refer to the 'campus_overview' object.
        2. If the user asks about times (e.g. hourly volume), the array index matches the 24-hour clock (index 14 = 2:00 PM).
        3. Keep your answer professional, highly concise, and strictly factual based ONLY on the provided data.
        4. Format your response in clean HTML that can be directly injected into a dashboard. Use <h4> for headers, <p> for text, <b> for emphasis, and <ul>/<li> for lists if necessary. Do not include markdown code blocks like \`\`\`html.
        `;

        try {
            // THE FIX: Fetch from this exact same file using the proxy action!
            const response = await fetch('main_analytics.php?action=ask_gemini', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contents: [{ parts: [{ text: prompt }] }],
                    generationConfig: { temperature: 0.2 }
                })
            });

            const aiData = await response.json();

            if (!response.ok) {
                throw new Error(aiData.error ? aiData.error.message : `HTTP Error: ${response.status}`);
            }

            if (aiData.candidates && aiData.candidates.length > 0) {
                let htmlOutput = aiData.candidates[0].content.parts[0].text;
                htmlOutput = htmlOutput.replace(/```html/g, '').replace(/```/g, '');

                resultBox.innerHTML = `
                    <div style="display:flex; gap:18px; align-items:flex-start;">
                        <div style="width: 45px; height: 45px; background: #f3e8ff; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas fa-robot" style="font-size:1.5rem; color:#8b5cf6;"></i>
                        </div>
                        <div style="flex:1;">
                            ${htmlOutput}
                        </div>
                    </div>`;
            } else {
                throw new Error("Google AI returned an empty response.");
            }

        } catch (error) {
            console.error("AI Analysis Error:", error);
            resultBox.innerHTML = `
                <div style="color:#ef4444; display:flex; align-items:center; gap:10px; font-weight:500;">
                    <i class="fas fa-exclamation-triangle" style="font-size:1.2rem;"></i> 
                    <span><b>API Error:</b> ${error.message}</span>
                </div>`;
        }
    }

    // ── ORIGINAL CHART LOGIC ──
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(() => { if (typeof hideGlobalLoader === 'function') hideGlobalLoader(); }, 400);

        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        if (sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', () => {
                setTimeout(() => { window.dispatchEvent(new Event('resize')); }, 300);
            });
        }

        const colors = { primary: '#3b82f6', secondary: '#93c5fd', success: '#10b981', danger: '#ef4444', warning: '#f59e0b', purple: '#8b5cf6' };

        new Chart(document.getElementById('tripsChart'), {
            type: 'line',
            data: { labels: <?= json_encode($dailyLabels) ?>, datasets: [{ label: 'Completed Trips', data: <?= json_encode(array_values($dailyVolume)) ?>, borderColor: colors.primary, backgroundColor: 'rgba(59, 130, 246, 0.1)', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 3 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });

        new Chart(document.getElementById('peakDemandChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(function ($h) {
                    return sprintf("%02d:00", $h);
                }, range(0, 23))) ?>, datasets: [{ label: 'Booking Volume', data: <?= json_encode(array_values($hourlyDemand)) ?>, backgroundColor: colors.purple, borderRadius: 4 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });

        // FEATURE 3: Zone Drill-down Interactive Chart
        new Chart(document.getElementById('zoneChart'), {
            type: 'bar',
            data: { labels: <?= json_encode($zoneLabels) ?>, datasets: [{ label: 'Revenue (RM)', data: <?= json_encode($zoneData) ?>, backgroundColor: colors.success, borderRadius: 4, hoverBackgroundColor: '#059669' }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                onHover: (e, elements) => { e.native.target.style.cursor = elements.length ? 'pointer' : 'default'; },
                onClick: (e, elements, chart) => {
                    if (elements.length > 0) {
                        const clickedZone = chart.data.labels[elements[0].index];
                        showGlobalLoader('Filtering Dashboard...');
                        window.location.href = `?days=<?= $daysFilter ?>&zone=${encodeURIComponent(clickedZone)}`;
                    }
                }
            }
        });

        new Chart(document.getElementById('hotStopsChart'), {
            type: 'bar',
            data: { labels: <?= json_encode($topStopsLabels) ?>, datasets: [{ label: 'Pickups', data: <?= json_encode($topStopsData) ?>, backgroundColor: colors.warning, borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { display: false } } } }
        });

        new Chart(document.getElementById('frequentRoutesChart'), {
            type: 'bar',
            data: { labels: <?= json_encode($topRoutesLabels) ?>, datasets: [{ label: 'Scheduled Runs', data: <?= json_encode($topRoutesData) ?>, backgroundColor: colors.primary, borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });

        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: { labels: ['On-Demand', 'Scheduled'], datasets: [{ data: [<?= $onDemandCompleted ?>, <?= $scheduledCompleted ?>], backgroundColor: [colors.primary, colors.secondary], borderWidth: 0, hoverOffset: 4 }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Poppins' } } } } }
        });

        new Chart(document.getElementById('cancelReasonChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($cancellationSplit)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($cancellationSplit)) ?>,
                    backgroundColor: [colors.warning, colors.danger, '#64748b', '#0ea5e9', '#d946ef', '#14b8a6', '#f43f5e'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 10,
                            padding: 8,
                            font: { family: 'Poppins', size: 10 }
                        }
                    }
                }
            }
        });
    });

    // EXPORT EXCEL/CSV LOGIC
    window.exportAnalyticsCSV = function () {
        const driverData = <?= json_encode(array_values($driverPerformance)) ?>;
        const passengerData = <?= json_encode(array_values($passengerPerformance)) ?>;
        const zoneL = <?= json_encode($zoneLabels) ?>; const zoneD = <?= json_encode($zoneData) ?>;
        const routeL = <?= json_encode($topRoutesLabels) ?>; const routeD = <?= json_encode($topRoutesData) ?>;
        const stopL = <?= json_encode($topStopsLabels) ?>; const stopD = <?= json_encode($topStopsData) ?>;
        const currentZoneFilter = "<?= htmlspecialchars($zoneFilter) ?>";

        let csv = `CAMPUSPULSE ANALYTICS REPORT (Last <?= $daysFilter ?> Days)\n`;
        if (currentZoneFilter) csv += `Filtered Zone: ${currentZoneFilter}\n`;
        csv += `\nOPERATIONAL VITALS\nTotal Revenue,RM <?= $totalRevenue ?>\nActive Users,<?= $currUsers ?>\nTotal Completed Trips,<?= $totalCompleted ?>\nOn-Demand Match Rate,<?= number_format($odAcceptanceRate, 1) ?>%\nAverage Shuttle Load Factor,<?= number_format($avgLoadFactor, 1) ?>%\nAverage Driver Rating,<?= number_format($avgRating, 1) ?> / 5\n\n`;

        csv += "ZONE REVENUE GENERATION\nZone Name,Revenue (RM)\n";
        zoneL.forEach((z, i) => { csv += `"${z}",${zoneD[i]}\n`; });

        csv += "\nTOP FREQUENT ROUTES\nRoute Name,Runs\n";
        routeL.forEach((r, i) => { csv += `"${r}",${routeD[i]}\n`; });

        csv += "\nTOP PICKUP STOPS\nStop Name,Pickups\n";
        stopL.forEach((s, i) => { csv += `"${s}",${stopD[i]}\n`; });

        csv += "\nTOP PASSENGERS\nStudent ID,Student Name,Trips,Spend (RM)\n";
        passengerData.sort((a, b) => b.trips - a.trips).slice(0, 15).forEach(pass => {
            csv += `"${pass.student_id}","${pass.name}",${pass.trips},${pass.spend}\n`;
        });

        csv += "\nDRIVER PERFORMANCE\nDriver ID,Driver Name,Trips Completed,Avg Rating\n";
        driverData.sort((a, b) => b.trips - a.trips).forEach(drv => {
            let rating = drv.count > 0 ? (drv.sum / drv.count).toFixed(2) : "Unrated";
            csv += `"${drv.driver_id}","${drv.name}",${drv.trips},${rating}\n`;
        });

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url;
        a.download = `CampusPulse_Report_${currentZoneFilter ? currentZoneFilter + '_' : ''}<?= date('Y-m-d') ?>.csv`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    };
</script>
</body>

</html>