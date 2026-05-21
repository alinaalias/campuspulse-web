<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];


$filterDate = $_GET['filter_date'] ?? '';

$historyLog = [];
$now = time();
$bufferSeconds = 900; // 15-minute buffer


$schedQuery = $firestore->collection('Schedules')
    ->where('driver_id', '=', $driverId)
    ->documents();

foreach ($schedQuery as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();
    $id = $doc->id();

    // Parse timestamp
    $dateStr = trim($d['date'] ?? '');
    $timeStr = trim($d['departure_time'] ?? '');
    $tripTimestamp = strtotime("$dateStr $timeStr");
    if (!$tripTimestamp)
        $tripTimestamp = $now;

    $standardDate = date('Y-m-d', $tripTimestamp);

    // Apply Date Filter if user searched for a specific date
    if ($filterDate && $standardDate !== $filterDate)
        continue;

    $isPastBuffer = ($now > ($tripTimestamp + $bufferSeconds));
    $dbStatus = trim(strtolower($d['status'] ?? 'unknown'));

    // CATCH-ALL RULE: If it's explicitly finished, OR if the time + 15 mins has passed, it belongs in History.
    if (in_array($dbStatus, ['completed', 'cancelled', 'missed']) || $isPastBuffer) {

        $route = "Scheduled Trip";
        if (!empty($d['route_id'])) {
            $rSnap = $firestore->collection('Routes')->document($d['route_id'])->snapshot();
            if ($rSnap->exists())
                $route = $rSnap->data()['route_name'];
        }

        // Accurately override UI status for abandoned trips
        if ($dbStatus === 'completed') {
            $displayStatus = 'Completed';
        } elseif ($dbStatus === 'cancelled') {
            $displayStatus = 'Cancelled';
        } elseif ($isPastBuffer && $dbStatus !== 'completed') {
            $displayStatus = 'Missed'; // Catches 'published', 'scheduled', and abandoned 'active' trips
        } else {
            $displayStatus = ucfirst($dbStatus);
        }

        $historyLog[] = [
            'id' => $id,
            'type' => 'schedule',
            'title' => $route,
            'subtitle' => 'Shuttle Route',
            'date' => $standardDate,
            'time' => $timeStr,
            'status' => $displayStatus,
            'count' => $d['booked_count'] ?? 0,
            'timestamp' => $tripTimestamp
        ];
    }
}


$odQuery = $firestore->collection('Bookings')
    ->where('driver_id', '=', $driverId)
    ->documents();

foreach ($odQuery as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();

    // PHP-Side Filter: Only process On-Demand types
    if (strtolower($d['type'] ?? '') !== 'ondemand')
        continue;

    $id = $doc->id();

    // Priority Timestamp parsing
    $rawTs = $d['completed_at'] ?? $d['updated_at'] ?? $d['created_at'] ?? $now;
    $ts = (is_object($rawTs) && method_exists($rawTs, 'get')) ? $rawTs->get()->format('U') : (is_numeric($rawTs) ? $rawTs : strtotime($rawTs));
    $dateStr = date('Y-m-d', $ts);

    // Apply Date Filter
    if ($filterDate && $dateStr !== $filterDate)
        continue;

    $dbStatus = trim(strtolower($d['status'] ?? 'unknown'));
    $isExplicitlyFinished = in_array($dbStatus, ['completed', 'cancelled', 'missed']);
    $isStaleActive = in_array($dbStatus, ['confirmed', 'arriving', 'onboard']) && ($dateStr < date('Y-m-d', $now));

    // Catch-All for On-Demand
    if ($isExplicitlyFinished || $isStaleActive) {
        $displayStatus = $isStaleActive ? 'Missed' : ucfirst($dbStatus);

        $historyLog[] = [
            'id' => $id,
            'type' => 'ondemand',
            'title' => 'On-Demand Ride',
            'subtitle' => ' to ' . ($d['destination_name'] ?? 'Destination'),
            'date' => $dateStr,
            'time' => date('H:i', $ts),
            'status' => $displayStatus,
            'count' => 1,
            'timestamp' => $ts
        ];
    }
}


usort($historyLog, function ($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

$pageTitle = 'Trip History';
$extraHead = '
<style>
    .filter-form { display: grid; grid-template-columns: 1fr auto; gap: 10px; margin-bottom: 25px; align-items: center; }
    @media (max-width: 480px) { .filter-form { grid-template-columns: 1fr; } .btn-clear { justify-content: center; } }
    .filter-input { width: 100%; padding: 14px 15px 14px 45px; border: none; border-radius: 14px; font-family: inherit; font-size: 0.95rem; color: #555; background: white; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04); outline: none; transition: box-shadow 0.2s; box-sizing: border-box; display: block; }
    .filter-input:focus { box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); }
    .btn-clear { background: #ffebee; color: #e74c3c; text-decoration: none; padding: 14px 18px; border-radius: 14px; font-weight: 600; font-size: 0.9rem; box-shadow: 0 4px 15px rgba(231, 76, 60, 0.1); display: flex; align-items: center; gap: 6px; transition: transform 0.2s; white-space: nowrap; flex-shrink: 0; }
    .btn-clear:active { transform: scale(0.95); }
    .history-card-link { display: block; text-decoration: none; color: inherit; transition: transform 0.2s, box-shadow 0.2s; }
    .history-card-link:hover .history-card { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06); border-color: var(--primary-blue); }
    .status-completed { color: #2ecc71; background: #eafaf1; padding: 4px 8px; border-radius: 6px; }
    .status-cancelled { color: #e74c3c; background: #fdedec; padding: 4px 8px; border-radius: 6px; }
    .status-missed { color: #f39c12; background: #fef5e7; padding: 4px 8px; border-radius: 6px; }
</style>';
include '../layout/driver/header.php';
?>

    <div class="driver-header">
        <div style="width: 100%; display: flex; align-items: center; gap: 15px;">
            <a href="driver_schedule.php" style="color: white; font-size: 1.2rem;"><i class="fas fa-arrow-left"></i></a>
            <div>
                <h2 style="margin: 0; font-size: 1.4rem; font-weight: 700;">Trip History</h2>
            </div>
        </div>
    </div>

    <div class="driver-container">

        <form method="GET" class="filter-form">
            <div style="position: relative; width: 100%;">
                <i class="fas fa-calendar-alt"
                    style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--primary-blue); font-size: 1.1rem; pointer-events: none;"></i>

                <input type="date" name="filter_date" value="<?= htmlspecialchars($filterDate) ?>" class="filter-input"
                    onchange="this.form.submit()">
            </div>

            <?php if ($filterDate): ?>
                <a href="driver_trip_history.php" class="btn-clear">
                    <i class="fas fa-times"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($historyLog)): ?>
            <div class="driver-card" style="text-align: center; padding: 50px 20px;">
                <i class="fas fa-history" style="font-size: 3rem; color: #eee; margin-bottom: 15px;"></i>
                <p style="color: #999;">No trips found.</p>
            </div>
        <?php else: ?>

            <?php
            $currentDate = '';
            foreach ($historyLog as $trip):
                // Date Grouping Header
                if ($trip['date'] !== $currentDate) {
                    $currentDate = $trip['date'];
                    $displayDate = ($currentDate === date('Y-m-d')) ? 'Today' :
                        (($currentDate === date('Y-m-d', strtotime('-1 day'))) ? 'Yesterday' : date('d M Y', strtotime($currentDate)));
                    echo "<div class='date-header'>$displayDate</div>";
                }

                // Styling Logic
                $cardClass = ($trip['type'] === 'schedule') ? 'schedule' : 'ondemand';
                $iconClass = ($trip['type'] === 'schedule') ? 'fa-bus' : 'fa-car';

                $statusLower = strtolower($trip['status']);
                if ($statusLower === 'completed')
                    $statusClass = 'status-completed';
                elseif ($statusLower === 'missed')
                    $statusClass = 'status-missed';
                else
                    $statusClass = 'status-cancelled';
                ?>

                <a href="trip_history_detail.php?id=<?= $trip['id'] ?>&type=<?= $trip['type'] ?>" class="history-card-link">
                    <div class="history-card <?= $cardClass ?>">

                        <div class="h-icon">
                            <i class="fas <?= $iconClass ?>"></i>
                        </div>

                        <div class="h-details">
                            <div class="h-title"><?= htmlspecialchars($trip['title']) ?></div>
                            <div class="h-sub">
                                <?= htmlspecialchars($trip['subtitle']) ?>
                                <?php if ($trip['type'] === 'schedule'): ?>
                                    • <?= $trip['count'] ?> Passengers
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="h-meta">
                            <div class="h-time"><?= $trip['time'] ?></div>
                            <div class="h-status <?= $statusClass ?>" style="font-size: 0.75rem; font-weight: 700;">
                                <?= htmlspecialchars($trip['status']) ?>
                            </div>
                        </div>

                        <div style="color: #ccc; margin-left: 10px;">
                            <i class="fas fa-chevron-right"></i>
                        </div>

                    </div>
                </a>

            <?php endforeach; ?>
        <?php endif; ?>

    </div>

<?php include '../layout/driver/footer.php'; ?>