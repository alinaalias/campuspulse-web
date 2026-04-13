<?php
session_start();
require_once '../config.php';

// 1. Security
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 2. Handle Date Filter
$filterDate = $_GET['filter_date'] ?? '';

// ===================================================================================
// DATA FETCHING & MERGING
// ===================================================================================
$historyLog = [];

// A. FETCH SCHEDULES
$schedQuery = $firestore->database()->collection('Schedules')
    ->where('driver_id', '=', $driverId)
    ->documents();

foreach ($schedQuery as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();
    $id = $doc->id();

    // Apply Date Filter if set
    if ($filterDate && $d['date'] !== $filterDate)
        continue;

    $isPast = ($d['date'] < date('Y-m-d')) || ($d['date'] === date('Y-m-d') && $d['departure_time'] < date('H:i'));
    $dbStatus = strtolower($d['status'] ?? 'scheduled');

    // Only show past trips or explicitly completed ones
    if ($isPast || $dbStatus === 'completed') {
        $route = "Scheduled Trip";
        if (isset($d['route_id'])) {
            $rSnap = $firestore->database()->collection('Routes')->document($d['route_id'])->snapshot();
            if ($rSnap->exists())
                $route = $rSnap->data()['route_name'];
        }

        // ACCURATE STATUS LOGIC
        if ($dbStatus === 'completed') {
            $displayStatus = 'Completed';
        } elseif ($dbStatus === 'cancelled') {
            $displayStatus = 'Cancelled';
        } elseif ($isPast && $dbStatus !== 'completed') {
            $displayStatus = 'Missed'; // The time passed, but it was never marked completed
        } else {
            $displayStatus = ucfirst($dbStatus);
        }

        $historyLog[] = [
            'id' => $id,
            'type' => 'schedule',
            'title' => $route,
            'subtitle' => 'Bus Route',
            'date' => $d['date'],
            'time' => $d['departure_time'],
            'status' => $displayStatus,
            'count' => $d['booked_count'] ?? 0,
            'timestamp' => strtotime($d['date'] . ' ' . $d['departure_time'])
        ];
    }
}

// B. FETCH COMPLETED ON-DEMAND REQUESTS
$odQuery = $firestore->database()->collection('Bookings')
    ->where('driver_id', '=', $driverId)
    ->where('type', '=', 'ondemand') // <--- THE MISSING FILTER ADDED HERE
    ->where('status', 'in', ['completed', 'cancelled', 'missed'])
    ->documents();

foreach ($odQuery as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();
    $id = $doc->id();

    // PRIORITY TIMESTAMP: When was it actually completed?
    $rawTs = $d['completed_at'] ?? $d['updated_at'] ?? $d['created_at'] ?? time();
    if (is_object($rawTs) && method_exists($rawTs, 'get')) {
        $ts = $rawTs->get()->format('U');
    } elseif (!is_numeric($rawTs)) {
        $ts = strtotime($rawTs);
    } else {
        $ts = $rawTs;
    }

    $dateStr = date('Y-m-d', $ts);

    // Apply Date Filter if set
    if ($filterDate && $dateStr !== $filterDate)
        continue;

    $historyLog[] = [
        'id' => $id,
        'type' => 'ondemand',
        'title' => 'On-Demand Ride',
        'subtitle' => ' to ' . ($d['destination_name'] ?? 'Destination'),
        'date' => $dateStr,
        'time' => date('H:i', $ts),
        'status' => ucfirst($d['status'] ?? 'Unknown'),
        'count' => 1,
        'timestamp' => $ts
    ];
}

// C. SORT BY NEWEST FIRST
usort($historyLog, function ($a, $b) {
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

    <style>
        .filter-form {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            align-items: center;
        }

        .filter-input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            /* Extra left padding for the icon */
            border: none;
            border-radius: 14px;
            font-family: inherit;
            font-size: 0.95rem;
            color: #555;
            background: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            outline: none;
            transition: box-shadow 0.2s;
            box-sizing: border-box;
        }

        .filter-input:focus {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .btn-clear {
            background: #ffebee;
            color: #e74c3c;
            text-decoration: none;
            padding: 14px 20px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.1);
            display: flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.2s;
        }

        .btn-clear:active {
            transform: scale(0.95);
        }

        /* Make the card act like a button */
        .history-card-link {
            display: block;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .history-card-link:hover .history-card {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            border-color: var(--primary-blue);
        }

        /* Specific Status Colors */
        .status-completed {
            color: #2ecc71;
            background: #eafaf1;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .status-cancelled {
            color: #e74c3c;
            background: #fdedec;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .status-missed {
            color: #f39c12;
            background: #fef5e7;
            padding: 4px 8px;
            border-radius: 6px;
        }
    </style>
</head>

<body class="driver-body">

    <div class="driver-header" style="height: 120px; align-items: flex-start; padding-top: 30px;">
        <div style="width: 100%; display: flex; align-items: center; gap: 15px;">
            <a href="driver_dashboard.php" style="color: white; font-size: 1.2rem;"><i
                    class="fas fa-arrow-left"></i></a>
            <h2 style="margin: 0; font-size: 1.4rem; font-weight: 600;">Trip History</h2>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -50px;">

        <form method="GET" class="filter-form">
            <div style="position: relative; flex: 1;">
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
                <p style="color: #999;">No trips found for this criteria.</p>
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
                                    &bull; <?= $trip['count'] ?> Passengers
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

    <?php include 'driver_navbar.php'; ?>

</body>

</html>