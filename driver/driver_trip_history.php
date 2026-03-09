<?php
session_start();
require_once '../config.php';

// 1. Security
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// ===================================================================================
// DATA FETCHING & MERGING
// ===================================================================================
$historyLog = [];

// A. FETCH COMPLETED SCHEDULES
$schedQuery = $firestore->database()->collection('Schedules')
    ->where('driver_id', '=', $driverId)
    ->documents();

foreach ($schedQuery as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    
    // Filter: Only show past trips or completed ones
    $isPast = ($d['date'] < date('Y-m-d')) || ($d['date'] === date('Y-m-d') && $d['departure_time'] < date('H:i'));
    $isCompleted = ($d['status'] ?? '') === 'completed';

    if ($isPast || $isCompleted) {
        $route = "Scheduled Trip";
        if(isset($d['route_id'])) {
            $rSnap = $firestore->database()->collection('Routes')->document($d['route_id'])->snapshot();
            if($rSnap->exists()) $route = $rSnap->data()['route_name'];
        }

        $historyLog[] = [
            'type' => 'schedule',
            'title' => $route,
            'subtitle' => 'Bus Route',
            'date' => $d['date'],
            'time' => $d['departure_time'],
            'status' => 'Completed',
            'count' => $d['booked_count'] ?? 0,
            'timestamp' => strtotime($d['date'] . ' ' . $d['departure_time'])
        ];
    }
}

// B. FETCH COMPLETED ON-DEMAND REQUESTS
$odQuery = $firestore->database()->collection('RideRequests')
    ->where('driver_id', '=', $driverId)
    ->where('status', 'in', ['completed', 'cancelled']) 
    ->documents();

foreach ($odQuery as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    
    // Timestamp handling
    $ts = $d['created_at'] ?? time(); 
    if (is_object($ts)) $ts = $ts->get()->format('U'); 
    elseif (!is_numeric($ts)) $ts = strtotime($ts);

    $historyLog[] = [
        'type' => 'ondemand',
        'title' => 'On-Demand Ride',
        'subtitle' => ' to ' . ($d['dropoff']['address'] ?? 'Destination'),
        'date' => date('Y-m-d', $ts),
        'time' => date('H:i', $ts),
        'status' => ucfirst($d['status']),
        'count' => 1,
        'timestamp' => $ts
    ];
}

// C. SORT BY NEWEST FIRST
usort($historyLog, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trip History</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="driver-body">

    <div class="driver-header" style="height: 120px; align-items: flex-start; padding-top: 30px;">
        <div style="width: 100%; display: flex; align-items: center; gap: 15px;">
            <a href="driver_dashboard.php" style="color: white; font-size: 1.2rem;"><i class="fas fa-arrow-left"></i></a>
            <h2 style="margin: 0; font-size: 1.4rem; font-weight: 600;">Trip History</h2>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -50px;">
        
        <?php if (empty($historyLog)): ?>
            <div class="driver-card" style="text-align: center; padding: 50px 20px;">
                <i class="fas fa-history" style="font-size: 3rem; color: #eee; margin-bottom: 15px;"></i>
                <p style="color: #999;">No trip history found.</p>
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
                $statusClass = (strtolower($trip['status']) === 'completed') ? 'status-completed' : 'status-cancelled';
            ?>

            <div class="history-card <?= $cardClass ?>">
                
                <div class="h-icon">
                    <i class="fas <?= $iconClass ?>"></i>
                </div>

                <div class="h-details">
                    <div class="h-title"><?= htmlspecialchars($trip['title']) ?></div>
                    <div class="h-sub">
                        <?= htmlspecialchars($trip['subtitle']) ?>
                        <?php if($trip['type'] === 'schedule'): ?>
                            &bull; <?= $trip['count'] ?> Passengers
                        <?php endif; ?>
                    </div>
                </div>

                <div class="h-meta">
                    <div class="h-time"><?= $trip['time'] ?></div>
                    <div class="h-status <?= $statusClass ?>">
                        <?= htmlspecialchars($trip['status']) ?>
                    </div>
                </div>

            </div>

            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <?php include 'driver_navbar.php'; ?>

</body>
</html>