<?php
require_once 'config.php';
session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $_SESSION['role'] ?? null;
// Set Timezone
date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');
$currentTime = date('H:i');

// 1. HANDLE FILTERS
$inputDate = $_GET['date'] ?? ''; 
$filterZone = $_GET['zone_id'] ?? ''; 

if (!empty($inputDate)) {
    $startDate = $inputDate;
    $endDate = $inputDate; 
} else {
    $startDate = $today;
    $endDate = date('Y-m-d', strtotime('+7 days')); 
}

// 2. FETCHING HELPERS (With Cache)
function getCachedCollection($firestore, $collectionName, $keyField, $valueField = null) {
    if (isset($_SESSION['cache_' . $collectionName]) && !empty($_SESSION['cache_' . $collectionName])) {
        return $_SESSION['cache_' . $collectionName];
    }
    $map = [];
    try {
        $docs = $firestore->database()->collection($collectionName)->documents();
        foreach ($docs as $doc) {
            $data = $doc->data();
            $id = $doc->id();
            if ($valueField) {
                $map[$id] = $data[$valueField] ?? 'Unknown';
            } else {
                $k = (!empty($keyField) && isset($data[$keyField])) ? $data[$keyField] : $id;
                $map[$k] = ($collectionName === 'Stops') ? ($data['name'] ?? 'Unknown') : $data;
            }
        }
        $_SESSION['cache_' . $collectionName] = $map;
    } catch (Exception $e) { }
    return $map;
}

$zonesMap = getCachedCollection($firestore, 'Zones', 'id', 'name'); 
$routesMap = getCachedCollection($firestore, 'Routes', 'id'); 
$stopsMap = getCachedCollection($firestore, 'Stops', 'stop_id'); 

// 3. FETCH SCHEDULES
$query = $firestore->database()->collection('Schedules')
    ->where('status', '=', 'published')
    ->where('date', '>=', $startDate)
    ->where('date', '<=', $endDate)
    ->orderBy('date', 'ASC')
    ->orderBy('departure_time', 'ASC');

try {
    $documents = $query->documents();
} catch (Exception $e) {
    $documents = []; 
}

// 4. GROUP DATA
$groupedSchedules = [];

if (!empty($documents)) {
    foreach ($documents as $doc) {
        $data = $doc->data();
        $rId = $data['route_id'];
        $schedDate = $data['date'];
        
        if (!isset($routesMap[$rId])) continue;
        $routeData = $routesMap[$rId];
        
        if (!empty($filterZone) && ($routeData['zone_id'] ?? '') !== $filterZone) continue;
        if ($schedDate === $today && $data['departure_time'] < $currentTime) continue;

        if (!isset($groupedSchedules[$rId])) {
            $groupedSchedules[$rId] = [
                'info' => $routeData,
                'dates' => [] 
            ];
        }
        $groupedSchedules[$rId]['dates'][$schedDate][] = $data;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shuttle Schedules - CampusPulse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <link rel="stylesheet" href="css/style.css"> 
</head>
<body class="schedule-body">

<?php include 'layout/public_header.php'; ?>

<div class="search-header">
    <h1>Find Your Shuttle</h1>
    <p>Check real-time schedules for all campus zones</p>
</div>

<form method="GET" class="search-container">
    <div class="filter-group">
        <label class="filter-label"><i class="far fa-calendar-alt"></i> Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($inputDate) ?>" class="form-control-custom">
    </div>
    
    <div class="filter-group">
        <label class="filter-label"><i class="fas fa-map-marker-alt"></i> Zone</label>
        <select name="zone_id" class="form-control-custom">
            <option value="">All Zones</option>
            <?php foreach ($zonesMap as $zId => $zName): ?>
                <option value="<?= $zId ?>" <?= $filterZone === $zId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($zName) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn-filter">
        <i class="fas fa-filter"></i> Apply
    </button>
    
    <a href="shuttle_schedule.php" class="btn-reset">
        <i class="fas fa-undo"></i> Reset
    </a>
</form>

<div class="route-grid">
    <?php if (empty($groupedSchedules)): ?>
        <div style="grid-column: 1/-1; text-align: center; padding: 60px;">
            <i class="fas fa-bus" style="font-size: 3rem; color: #ddd; margin-bottom: 20px;"></i>
            <h3 style="color:#555;">No schedules found</h3>
            <p style="color:#999;">Try selecting a different date or zone.</p>
        </div>
    <?php else: ?>
        <?php foreach ($groupedSchedules as $rId => $group): 
            $routeInfo = $group['info'];
            $startName = isset($stopsMap[$routeInfo['start_stop_id']]) ? $stopsMap[$routeInfo['start_stop_id']] : 'Start';
            $endName = isset($stopsMap[$routeInfo['end_stop_id']]) ? $stopsMap[$routeInfo['end_stop_id']] : 'End';
            $zId = $routeInfo['zone_id'] ?? '';
            $zName = $zonesMap[$zId] ?? 'General';
        ?>
        
        <div class="route-card">
            
            <div class="card-header-clean">
                <span class="zone-badge"><?= htmlspecialchars($zName) ?></span>
                <div class="live-status">
                    <div class="live-dot"></div> Active
                </div>
            </div>

            <div class="card-main-info">
                <div class="route-title"><?= htmlspecialchars($routeInfo['route_name'] ?? $rId) ?></div>
                
                <div class="route-path-container">
                    <span style="font-weight:600;"><?= htmlspecialchars($startName) ?></span>
                    <i class="fas fa-arrow-right path-arrow"></i>
                    <span style="font-weight:600;"><?= htmlspecialchars($endName) ?></span>
                </div>
            </div>

            <div class="card-schedule">
                <?php foreach($group['dates'] as $dateStr => $trips): 
                    $label = ($dateStr == $today) ? "Today" : date('D, d M', strtotime($dateStr));
                    $isToday = ($dateStr == $today);
                ?>
                <div class="date-row">
                    <div class="date-header <?= $isToday ? 'today' : '' ?>">
                        <?= $label ?>
                    </div>
                    <div class="time-grid">
                        <?php foreach($trips as $trip): 
                            $timeStr = date('h:i A', strtotime($trip['departure_time']));
                        ?>
                            <div class="time-pill <?= $isToday ? 'highlight' : '' ?>">
                                <?= $timeStr ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="card-footer-cta">
                <i class="fas fa-mobile-alt"></i> 
                <strong>Book a ride via CampusPulse App</strong>
            </div>
        </div>

        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'layout/footer.php'; ?>

</body>
</html>