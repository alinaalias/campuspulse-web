<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 2. Validate Input
$tripId = $_GET['id'] ?? '';
$tripType = $_GET['type'] ?? ''; // 'schedule' or 'ondemand'

if (!$tripId || !in_array($tripType, ['schedule', 'ondemand'])) {
    $_SESSION['error'] = "Invalid trip details requested.";
    header('Location: driver_trip_history.php');
    exit();
}

$tripData = null;
$passengerLog = [];
$routeDetails = [];

try {
    $db = $firestore->database();

    // ==========================================
    // LOGIC FOR SCHEDULED TRIPS (BUS ROUTES)
    // ==========================================
    if ($tripType === 'schedule') {
        // Fetch Schedule Details
        $schedSnap = $db->collection('Schedules')->document($tripId)->snapshot();
        if (!$schedSnap->exists() || $schedSnap->data()['driver_id'] !== $driverId) {
            throw new Exception("Schedule not found or unauthorized.");
        }
        $tripData = $schedSnap->data();
        $tripData['id'] = $schedSnap->id();

        // Fetch Route Name
        $routeName = "Unknown Route";
        if (!empty($tripData['route_id'])) {
            $rSnap = $db->collection('Routes')->document($tripData['route_id'])->snapshot();
            if ($rSnap->exists()) {
                $routeDetails = $rSnap->data();
                $routeName = $routeDetails['route_name'];
            }
        }
        $tripData['display_title'] = $routeName;
        $tripData['display_icon'] = 'fa-bus';
        $tripData['display_type'] = 'Scheduled Bus Route';

        // Fetch Passengers
        $bookingsQuery = $db->collection('Bookings')
            ->where('schedule_id', '=', $tripId)
            ->where('status', 'in', ['onboard', 'completed'])
            ->documents();

        foreach ($bookingsQuery as $bDoc) {
            $bData = $bDoc->data();

            // Get Student Name
            $studentName = "Unknown Student";
            $studentId = $bData['user_id'] ?? ($bData['student_id'] ?? '');
            if ($studentId) {
                $stSnap = $db->collection('Students')->document($studentId)->snapshot();
                if ($stSnap->exists()) {
                    $studentName = $stSnap->data()['full_name'] ?? 'Student';
                }
            }

            // Get Stop Names
            $pickupName = $bData['pickup_stop_id'] ?? 'Unknown Stop';
            if ($pickupName) {
                $pSnap = $db->collection('Stops')->document($pickupName)->snapshot();
                if ($pSnap->exists())
                    $pickupName = $pSnap->data()['name'] ?? ($pSnap->data()['stop_name'] ?? $pickupName);
            }

            $passengerLog[] = [
                'name' => $studentName,
                'pickup' => $pickupName,
                'check_in_time' => $bData['check_in_time'] ?? 'Unknown Time',
                'status' => 'Boarded'
            ];
        }

        // Sort passengers by check-in time
        usort($passengerLog, function ($a, $b) {
            return strtotime($a['check_in_time']) - strtotime($b['check_in_time']);
        });

    }
    // ==========================================
    // LOGIC FOR ON-DEMAND TRIPS (RIDE HAILING)
    // ==========================================
    else if ($tripType === 'ondemand') {
        $bookingSnap = $db->collection('Bookings')->document($tripId)->snapshot();
        if (!$bookingSnap->exists() || $bookingSnap->data()['driver_id'] !== $driverId) {
            throw new Exception("On-Demand record not found or unauthorized.");
        }
        $bData = $bookingSnap->data();

        $tripData = $bData;
        $tripData['id'] = $bookingSnap->id();
        $tripData['display_title'] = "On-Demand Ride";
        $tripData['display_icon'] = 'fa-car';
        $tripData['display_type'] = 'Point-to-Point Transit';

        // Handle Missing Shuttle ID
        $shuttleId = $bData['shuttle_id'] ?? '';
        if (empty($shuttleId)) {
            $driverSnap = $db->collection('Staffs')->document($driverId)->snapshot();
            if ($driverSnap->exists()) {
                $shuttleId = $driverSnap->data()['assigned_shuttle_id'] ?? 'Unknown Vehicle';
            }
        }
        $tripData['shuttle_id'] = $shuttleId;

        // --- SYNCED TIMESTAMP LOGIC ---
        $rawTs = $bData['completed_at'] ?? $bData['updated_at'] ?? $bData['created_at'] ?? time();
        if (is_object($rawTs) && method_exists($rawTs, 'get')) {
            $ts = $rawTs->get()->format('U');
        } elseif (!is_numeric($rawTs)) {
            $ts = strtotime($rawTs);
        } else {
            $ts = $rawTs;
        }

        $tripData['date'] = date('Y-m-d', $ts);
        $tripData['departure_time'] = date('H:i', $ts);
        // ------------------------------

        // TRANSLATE STOP IDs TO REAL NAMES
        $pId = $bData['pickup_stop_id'] ?? '';
        $dId = $bData['dropoff_stop_id'] ?? '';

        $pickupName = "Current Location";
        if ($pId) {
            $pSnap = $db->collection('Stops')->document($pId)->snapshot();
            if ($pSnap->exists())
                $pickupName = $pSnap->data()['name'] ?? ($pSnap->data()['stop_name'] ?? $pId);
        }

        $dropoffName = "Destination";
        if ($dId) {
            $dSnap = $db->collection('Stops')->document($dId)->snapshot();
            if ($dSnap->exists())
                $dropoffName = $dSnap->data()['name'] ?? ($dSnap->data()['stop_name'] ?? $dId);
        }

        // Get Single Passenger Details
        $studentName = "Unknown Student";
        $studentId = $bData['user_id'] ?? ($bData['student_id'] ?? '');
        if ($studentId) {
            $stSnap = $db->collection('Students')->document($studentId)->snapshot();
            if ($stSnap->exists()) {
                $studentName = $stSnap->data()['full_name'] ?? 'Student';
            }
        }

        $passengerLog[] = [
            'name' => $studentName,
            'pickup' => $pickupName,   // Uses the translated name
            'dropoff' => $dropoffName, // Uses the translated name
            'check_in_time' => $bData['completed_at'] ?? ($bData['check_in_time'] ?? date('Y-m-d H:i', $ts)),
            'status' => ucfirst($bData['status'] ?? 'Completed')
        ];
    }

} catch (Exception $e) {
    $_SESSION['error'] = "Error loading trip details: " . $e->getMessage();
    header('Location: driver_trip_history.php');
    exit();
}

// Calculate Display Status
$statusLower = strtolower($tripData['status'] ?? 'unknown');
$statusClass = 'status-cancelled'; // default red
if ($statusLower === 'completed')
    $statusClass = 'status-completed';
elseif ($statusLower === 'missed')
    $statusClass = 'status-missed';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Trip Summary</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .summary-header {
            background: white;
            padding: 25px 20px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            margin-bottom: 20px;
            text-align: center;
        }

        .icon-circle {
            width: 60px;
            height: 60px;
            background: #f0f4f8;
            color: var(--primary-blue);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        .stat-box {
            text-align: center;
        }

        .stat-box .label {
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .stat-box .value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
        }

        /* Passenger List Styles */
        .passenger-list {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .list-header {
            padding: 15px 20px;
            background: #fafafa;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            color: #555;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .passenger-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f5f5f5;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .passenger-item:last-child {
            border-bottom: none;
        }

        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-blue);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* Status Colors */
        .status-completed {
            color: #2ecc71;
            background: #eafaf1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-cancelled {
            color: #e74c3c;
            background: #fdedec;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-missed {
            color: #f39c12;
            background: #fef5e7;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>

<body class="driver-body" style="background-color: #f4f6f9;">

    <div class="driver-header" style="height: 120px; align-items: flex-start; padding-top: 30px;">
        <div style="width: 100%; display: flex; align-items: center; gap: 15px;">
            <a href="driver_trip_history.php" style="color: white; font-size: 1.2rem;"><i
                    class="fas fa-arrow-left"></i></a>
            <h2 style="margin: 0; font-size: 1.4rem; font-weight: 600;">Trip Summary</h2>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -50px; padding-bottom: 40px;">

        <div class="summary-header">
            <div class="icon-circle">
                <i class="fas <?= $tripData['display_icon'] ?>"></i>
            </div>
            <h3 style="margin: 0 0 5px 0; color: #333;"><?= htmlspecialchars($tripData['display_title']) ?></h3>
            <p style="margin: 0 0 15px 0; color: #888; font-size: 0.9rem;"><?= $tripData['display_type'] ?></p>

            <span class="<?= $statusClass ?>">
                <?= htmlspecialchars(ucfirst($tripData['status'] ?? 'Unknown')) ?>
            </span>

            <div class="stat-grid">
                <div class="stat-box" style="border-right: 1px solid #eee;">
                    <div class="label">Date & Time</div>
                    <div class="value">
                        <?= date('d M Y', strtotime($tripData['date'])) ?><br>
                        <span style="color: var(--primary-blue);"><?= $tripData['departure_time'] ?></span>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="label">Vehicle</div>
                    <div class="value" style="padding-top: 10px;">
                        <?= htmlspecialchars($tripData['shuttle_id'] ?? 'N/A') ?>
                    </div>
                </div>
            </div>
        </div>

        <h4 style="margin: 0 0 15px 5px; color: #555; font-size: 1rem;">Passenger Manifest</h4>

        <div class="passenger-list">
            <div class="list-header">
                <span><?= count($passengerLog) ?> Boarded</span>
                <i class="fas fa-users" style="color: #aaa;"></i>
            </div>

            <?php if (empty($passengerLog)): ?>
                <div style="padding: 30px; text-align: center; color: #999;">
                    <i class="fas fa-user-slash fa-2x" style="margin-bottom: 10px; color: #ddd;"></i>
                    <p style="margin: 0;">No passengers recorded for this trip.</p>
                </div>
            <?php else: ?>
                <?php foreach ($passengerLog as $p):
                    $initial = strtoupper(substr(trim($p['name']), 0, 1));
                    ?>
                    <div class="passenger-item">
                        <div class="avatar-placeholder"><?= $initial ?></div>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #333; margin-bottom: 4px;">
                                <?= htmlspecialchars($p['name']) ?>
                            </div>

                            <?php if ($tripType === 'ondemand'): ?>
                                <div style="font-size: 0.8rem; color: #666; margin-bottom: 2px;">
                                    <i class="fas fa-arrow-up" style="color:var(--primary-blue); width:12px;"></i> From: <span
                                        style="font-weight:500;"><?= htmlspecialchars($p['pickup']) ?></span>
                                </div>
                                <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">
                                    <i class="fas fa-arrow-down" style="color:#e74c3c; width:12px;"></i> To: <span
                                        style="font-weight:500;"><?= htmlspecialchars($p['dropoff']) ?></span>
                                </div>
                            <?php else: ?>
                                <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">
                                    <i class="fas fa-map-pin" style="color:var(--primary-blue); width:12px;"></i> Boarded at: <span
                                        style="font-weight:500;"><?= htmlspecialchars($p['pickup']) ?></span>
                                </div>
                            <?php endif; ?>

                            <div style="font-size: 0.75rem; color: #aaa;">
                                <i class="far fa-clock" style="width:12px;"></i>
                                <?= date('h:i A', strtotime($p['check_in_time'])) ?>
                            </div>
                        </div>
                        <div style="font-size: 0.85rem; color: #2ecc71; font-weight: 600;">
                            <i class="fas fa-check"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

</body>

</html>