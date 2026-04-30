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
$rawId = $_GET['id'] ?? '';

if (!$rawId) {
    header('Location: driver_dashboard.php');
    exit();
}

$db = $firestore->database();

// GATEKEEPER - Admin Status Check & Compliance
$driverSnap = $db->collection('Staffs')->document($driverId)->snapshot();
$driverData = $driverSnap->data();

$todayDate = new DateTime('today');
$licExp = $driverData['license_expiry'] ?? '';
$psvExp = $driverData['psv_expiry'] ?? '';
$licDays = !empty($licExp) ? (int) $todayDate->diff(new DateTime($licExp))->format('%r%a') : null;
$psvDays = !empty($psvExp) ? (int) $todayDate->diff(new DateTime($psvExp))->format('%r%a') : null;
$isExpired = (($licDays !== null && $licDays < 0) || ($psvDays !== null && $psvDays < 0));
$status = $driverData['status'] ?? '';

if ($status === 'suspended' || $status === 'inactive' || $status === 'pending_review' || $isExpired) {
    $_SESSION['requires_compliance_update'] = true;
    header('Location: driver_profile.php');
    exit();
} else {
    unset($_SESSION['requires_compliance_update']);
}
try {
    $db->collection('Staffs')->document($driverId)->update([
        ['path' => 'current_trip_id', 'value' => $rawId]
    ]);
} catch (Exception $e) {
}

$tripType = '';
$tripData = [];
$stopsData = [];
$onboardCount = 0;
$bookedCount = 0;
$capacity = 13;
$shuttleId = '';
$bookingStatus = '';
$passengerName = 'Student';

// 3. SMART DATA LOADING
if (strpos($rawId, 'SCHED:') === 0) {
    $tripType = 'schedule';
    $scheduleId = substr($rawId, 6);

    $schedRef = $db->collection('Schedules')->document($scheduleId);
    $schedSnap = $schedRef->snapshot();

    if (!$schedSnap->exists()) {
        echo "<script>alert('Schedule not found.'); window.location.href='driver_dashboard.php';</script>";
        exit();
    }

    $tripData = $schedSnap->data();

    // The Bouncer & Auto-Active
    if (($tripData['status'] ?? '') === 'completed') {
        echo "<script>alert('Trip already completed'); window.location.href='driver_dashboard.php';</script>";
        exit();
    }
    if (($tripData['status'] ?? '') === 'cancelled') {
        echo "<script>alert('This trip was cancelled'); window.location.href='driver_dashboard.php';</script>";
        exit();
    }

    // --- FIXED: STRICT 15-MINUTE BUFFER (900 seconds) ---
    $tripDateTimeStr = ($tripData['date'] ?? '') . ' ' . ($tripData['departure_time'] ?? '');
    $tripTimestamp = strtotime($tripDateTimeStr);
    $bufferSeconds = 900;

    if ($tripTimestamp && (time() > ($tripTimestamp + $bufferSeconds))) {
        // Auto-clean DB to "missed" if it was left hanging in "published" or "scheduled"
        if (!in_array(($tripData['status'] ?? ''), ['missed', 'completed', 'cancelled', 'active'])) {
            try {
                $schedRef->update([['path' => 'status', 'value' => 'missed']]);
            } catch (Exception $e) {
            }
        }
        echo "<script>alert('This scheduled trip has expired and is now marked as Missed.'); window.location.href='driver_trip_history.php';</script>";
        exit();
    }
    // -----------------------------------------

    // FIX: Also look for 'published' from auto_generate script!
    if (in_array(($tripData['status'] ?? ''), ['scheduled', 'published'])) {
        $tripDate = $tripData['date'] ?? '';
        if ($tripDate > date('Y-m-d')) {
            echo "<script>alert('Cannot start a trip scheduled for a future date!'); window.location.href='driver_schedule.php';</script>";
            exit();
        }
        try {
            $schedRef->update([['path' => 'status', 'value' => 'active']]);
            $tripData['status'] = 'active';
        } catch (Exception $e) {
        }
    }

    $shuttleId = $tripData['shuttle_id'] ?? '';
    $onboardCount = $tripData['onboard_count'] ?? 0;
    $bookedCount = $tripData['booked_count'] ?? 0;
    $capacity = $tripData['capacity'] ?? 13;

    $etas = $tripData['etas'] ?? [];
    if (!empty($etas)) {
        asort($etas);
        $stopIds = array_keys($etas);

        foreach ($stopIds as $sid) {
            $sSnap = $db->collection('Stops')->document($sid)->snapshot();
            if ($sSnap->exists()) {
                $d = $sSnap->data();
                $stopsData[] = [
                    'id' => $sid,
                    'name' => $d['stop_name'] ?? $d['name'] ?? $sid,
                    'lat' => $d['latitude'] ?? $d['lat'] ?? null,
                    'lng' => $d['longitude'] ?? $d['lng'] ?? null,
                    'eta' => $etas[$sid],
                    'type' => 'stop'
                ];
            }
        }
    } else {
        // Fallback for static routes
        $routeSnap = $db->collection('Routes')->document($tripData['route_id'])->snapshot();
        $stopIds = $routeSnap->exists() ? ($routeSnap->data()['stop_ids'] ?? []) : [];
        foreach ($stopIds as $sid) {
            $sSnap = $db->collection('Stops')->document($sid)->snapshot();
            if ($sSnap->exists()) {
                $d = $sSnap->data();
                $stopsData[] = [
                    'id' => $sid,
                    'name' => $d['stop_name'] ?? $d['name'] ?? $sid,
                    'lat' => $d['latitude'] ?? $d['lat'] ?? null,
                    'lng' => $d['longitude'] ?? $d['lng'] ?? null,
                    'eta' => '',
                    'type' => 'stop'
                ];
            }
        }
    }

} elseif (strpos($rawId, 'BOOK:') === 0) {
    $tripType = 'ondemand';
    $bookingId = substr($rawId, 5);

    $bookingRef = $db->collection('Bookings')->document($bookingId);
    $bookingSnap = $bookingRef->snapshot();
    if (!$bookingSnap->exists()) {
        echo "<script>alert('Booking not found.'); window.location.href='driver_dashboard.php';</script>";
        exit();
    }

    $tripData = $bookingSnap->data();

    // The Bouncer
    if (($tripData['status'] ?? '') === 'completed') {
        echo "<script>alert('Trip already completed'); window.location.href='driver_dashboard.php';</script>";
        exit();
    }

    $bookingStatus = $tripData['status'];

    if (!empty($tripData['user_id'])) {
        $stSnap = $db->collection('Students')->document($tripData['user_id'])->snapshot();
        if ($stSnap->exists()) {
            $passengerName = $stSnap->data()['full_name'] ?? "Student";
        }
    }

    $pId = $tripData['pickup_stop_id'] ?? '';
    $dId = $tripData['dropoff_stop_id'] ?? '';

    if ($pId) {
        $pSnap = $db->collection('Stops')->document($pId)->snapshot();
        if ($pSnap->exists()) {
            $d = $pSnap->data();
            $stopsData[] = [
                'id' => $pId,
                'name' => $d['stop_name'] ?? $d['name'] ?? $pId,
                'lat' => $d['latitude'] ?? $d['lat'] ?? null,
                'lng' => $d['longitude'] ?? $d['lng'] ?? null,
                'eta' => 'Pickup',
                'type' => 'pickup'
            ];
        }
    }

    if ($dId) {
        $dSnap = $db->collection('Stops')->document($dId)->snapshot();
        if ($dSnap->exists()) {
            $d = $dSnap->data();
            $stopsData[] = [
                'id' => $dId,
                'name' => $d['stop_name'] ?? $d['name'] ?? $dId,
                'lat' => $d['latitude'] ?? $d['lat'] ?? null,
                'lng' => $d['longitude'] ?? $d['lng'] ?? null,
                'eta' => 'Dropoff',
                'type' => 'dropoff'
            ];
        }
    }
} else {
    echo "Invalid Trip format.";
    exit();
}

$seatsLeft = max(0, $capacity - $bookedCount);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Active Trip</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
    <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-firestore.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Poppins', sans-serif;
            overflow: hidden;
            background: #f4f6f9;
        }

        /* Map Layer */
        #map {
            width: 100%;
            height: 80%;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
        }

        /* Top Bar Controls */
        .top-bar {
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .btn-round {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: white;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            text-decoration: none;
            font-size: 1.2rem;
            cursor: pointer;
            border: none;
        }

        .nav-trigger {
            background: #4285F4;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 30px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(66, 133, 244, 0.4);
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.95rem;
            margin-top: 10px;
        }

        /* Draggable Control Card */
        .control-card {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 25%;
            /* Default Start Height */
            background: white;
            border-top-left-radius: 25px;
            border-top-right-radius: 25px;
            box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15);
            z-index: 10;
            display: flex;
            flex-direction: column;
            /* Transition is managed dynamically in JS */
        }

        .drag-handle {
            width: 40px;
            height: 5px;
            background: #e0e0e0;
            border-radius: 5px;
            margin: 15px auto;
            pointer-events: none;
        }

        /* Scrollable Content Area */
        .content-scroll {
            overflow-y: auto;
            flex: 1;
            padding: 0 25px 20px;
            -webkit-overflow-scrolling: touch;
            /* Smooth iOS scrolling */
            overscroll-behavior: contain;
            /* Prevents scroll chaining */
        }

        .ins-header {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .ins-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #2d3748;
            margin: 5px 0 15px;
            line-height: 1.2;
        }

        /* Tabs & Trip Log */
        .card-tabs {
            display: flex;
            background: #f1f3f5;
            border-radius: 12px;
            margin-bottom: 20px;
            padding: 4px;
        }

        .card-tab {
            flex: 1;
            text-align: center;
            padding: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #718096;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.2s;
        }

        .card-tab.active {
            background: white;
            color: var(--primary-blue);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .trip-log-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-bottom: 20px;
        }

        .log-entry {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 3px solid #cbd5e0;
        }

        .log-entry.check-in {
            border-left-color: #2ecc71;
            background: #e8f8f5;
        }

        .log-entry.system-log {
            border-left-color: #3498db;
        }

        .log-time {
            font-size: 0.75rem;
            color: #a0aec0;
            font-weight: 600;
            min-width: 45px;
            margin-top: 2px;
        }

        .log-msg {
            font-size: 0.9rem;
            color: #2d3748;
            font-weight: 500;
            line-height: 1.3;
            flex: 1;
        }

        .log-msg b {
            font-weight: 700;
        }

        /* Stats Row */
        .stats-row {
            display: flex;
            justify-content: space-between;
            text-align: center;
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 20px;
        }

        .stats-row>div {
            flex: 1;
        }

        .stats-row>div:not(:last-child) {
            border-right: 1px solid #e0e0e0;
        }

        /* Timeline Fixes */
        .timeline {
            margin: 15px 0;
            padding-left: 15px;
            position: relative;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: #e2e8f0;
        }

        .stop-item {
            position: relative;
            margin-bottom: 25px;
            padding-left: 20px;
        }

        .stop-item:last-child {
            margin-bottom: 0;
        }

        .s-dot {
            position: absolute;
            left: -21px;
            top: 1px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: white;
            border: 3px solid #cbd5e0;
            box-sizing: border-box;
            /* THIS FIXES THE HALF-BULLET BUG */
        }

        .s-text {
            font-weight: 600;
            color: #4a5568;
            font-size: 1.05rem;
        }

        .s-meta {
            font-size: 0.8rem;
            color: #a0aec0;
        }

        .s-active .s-dot {
            border-color: #3498db;
            background: #3498db;
            box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.2);
        }

        .s-active .s-text {
            color: #3498db;
            font-weight: 700;
        }

        .s-done .s-dot {
            border-color: #2ecc71;
            background: #2ecc71;
        }

        .s-done .s-text {
            color: #718096;
            text-decoration: line-through;
        }

        /* Bottom Actions */
        .bottom-actions {
            padding: 15px 25px;
            background: white;
            border-top: 1px solid #f1f3f5;
        }

        .btn-massive {
            width: 100%;
            padding: 18px;
            border-radius: 16px;
            font-size: 1.15rem;
            font-weight: 700;
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-massive:active {
            transform: scale(0.96);
        }

        .btn-massive.blue {
            background: #3498db;
        }

        .btn-massive.green {
            background: #2ecc71;
        }

        .btn-massive.red {
            background: #e74c3c;
            box-shadow: 0 6px 15px rgba(231, 76, 60, 0.2);
        }

        /* Floating QR Button */
        .qr-btn {
            position: absolute;
            right: 20px;
            bottom: calc(25% + 20px);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #2c3e50;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            z-index: 10;
            cursor: pointer;
            border: none;
        }

        /* Modals & Alerts */
        #scanModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        #reader {
            width: 320px;
            border-radius: 16px;
            overflow: hidden;
            background: #000;
        }

        #toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #2ecc71;
            color: white;
            padding: 12px 25px;
            border-radius: 30px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: none;
        }

        #cancelAlert {
            display: none;
            position: fixed;
            inset: 0;
            background: #e74c3c;
            color: white;
            z-index: 99999;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 30px;
        }
    </style>
</head>

<body>

    <div id="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg">Success</span></div>

    <div id="cancelAlert">
        <i class="fas fa-exclamation-triangle" style="font-size: 4rem; margin-bottom: 20px;"></i>
        <h1 style="margin: 0 0 10px;">Trip Cancelled</h1>
        <p style="font-size: 1.2rem; opacity: 0.9; margin-bottom: 30px;">The student has cancelled this request. You may
            return to the dashboard.</p>
        <button onclick="window.location.href='driver_dashboard.php'"
            style="background: white; color: #e74c3c; border: none; padding: 15px 30px; border-radius: 30px; font-weight: 700; font-size: 1.1rem; cursor: pointer;">RETURN
            TO DASHBOARD</button>
    </div>

    <div id="map"></div>
    <div class="top-bar">
        <a href="driver_dashboard.php" class="btn-round"><i class="fas fa-arrow-left"></i></a>
        <div style="display:flex; flex-direction:column; align-items:flex-end;">
            <a href="#" id="navBtn" class="nav-trigger" target="_blank">
                Navigate <i class="fas fa-location-arrow"></i>
            </a>
        </div>
    </div>

    <button class="qr-btn" onclick="startScanner()"><i class="fas fa-qrcode"></i></button>

    <div class="control-card" id="controlCard">
        <div class="drag-handle" id="dragHandle"></div>
        <div class="content-scroll">
            <div class="ins-header" id="insHeader">Next Goal</div>
            <div class="ins-title" id="insTitle">Loading...</div>

            <div class="card-tabs">
                <div class="card-tab active" id="tabBtn-route" onclick="switchCardTab('route')">Route Map</div>
                <div class="card-tab" id="tabBtn-log" onclick="switchCardTab('log')">Trip Log</div>
            </div>

            <div id="tabContent-route" class="tab-content active">
                <?php if ($tripType === 'schedule'): ?>
                    <div class="stats-row">
                        <div>
                            <div style="font-size:1.3rem; font-weight:700; color:#3498db;" id="uiOnboard">
                                <?= $onboardCount ?>
                            </div>
                            <div style="font-size:0.7rem; color:#888; font-weight:700;">ONBOARD</div>
                        </div>
                        <div>
                            <div style="font-size:1.3rem; font-weight:700; color:#f39c12;"><?= $bookedCount ?></div>
                            <div style="font-size:0.7rem; color:#888; font-weight:700;">BOOKED</div>
                        </div>
                        <div>
                            <div style="font-size:1.3rem; font-weight:700; color:#2ecc71;"><?= $seatsLeft ?></div>
                            <div style="font-size:0.7rem; color:#888; font-weight:700;">SEATS LEFT</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="timeline" id="timelineList">
                </div>
            </div>

            <div id="tabContent-log" class="tab-content" style="display: none;">
                <div class="trip-log-container" id="tripLogContainer">
                </div>
            </div>
        </div>

        <div class="bottom-actions">
            <button id="primaryAction" class="btn-massive blue" onclick="handlePrimaryAction()">
                Loading <i class="fas fa-spinner fa-spin"></i>
            </button>
        </div>
    </div>

    <div id="scanModal">
        <div style="position:absolute; top:20px; right:20px; color:white; font-size:2.5rem; cursor:pointer; z-index: 10000;"
            onclick="stopScanner()">×</div>
        <h3 style="color:white; margin-bottom:20px;">Scan Passenger Ticket</h3>
        <div id="reader"></div>
    </div>

    <form id="finishForm" action="finish_trip.php" method="POST" style="display:none;">
        <input type="hidden" name="schedule_id" value="<?= htmlspecialchars($rawId) ?>">
    </form>

    <script async defer
        src="https://maps.googleapis.com/maps/api/js?key=<?= MAPS_API_KEY ?>&callback=initMap&loading=async"></script>
    <script>
        const rawId = "<?= $rawId ?>";
        const tripType = "<?= $tripType ?>";
        const stopsData = <?= json_encode($stopsData) ?>;
        const passengerName = "<?= htmlspecialchars($passengerName) ?>";
        let onDemandStatus = "<?= $bookingStatus ?>";

        let map, directionsService, directionsRenderer;
        let currentLocation = null;
        let optimizedStops = [];
        let currentTargetIndex = 0;
        let wakeLock = null;

        async function requestWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    wakeLock = await navigator.wakeLock.request('screen');
                }
            } catch (err) { }
        }

        const firebaseConfig = {
            apiKey: "<?= MAPS_API_KEY ?? '' ?>",
            authDomain: "<?= FIREBASE_AUTH_DOMAIN ?? '' ?>",
            projectId: "<?= FIREBASE_PROJECT_ID ?? '' ?>"
        };
        if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
        const firestore = firebase.firestore();

        function initMap() {
            requestWakeLock();
            directionsService = new google.maps.DirectionsService();
            directionsRenderer = new google.maps.DirectionsRenderer({ suppressMarkers: false });
            map = new google.maps.Map(document.getElementById("map"), {
                zoom: 14, center: { lat: 3.1592, lng: 101.7036 }, disableDefaultUI: true,
                styles: [{ featureType: "poi", stylers: [{ visibility: "off" }] }]
            });
            directionsRenderer.setMap(map);
            calculateRoute();
            trackLocation();
            if (tripType === 'ondemand') listenToBooking();
            loadTripLogs();
        }

        function listenToBooking() {
            const bookingId = rawId.substring(5);
            firestore.collection("Bookings").doc(bookingId).onSnapshot((doc) => {
                if (doc.exists) {
                    const status = doc.data().status;
                    if (status === 'cancelled') {
                        document.getElementById('cancelAlert').style.display = 'flex';
                    } else if (status === 'completed') {
                        // The student marked the trip as complete
                        window.location.href = `trip_history_detail.php?id=BOOK:${bookingId}&type=ondemand&completed=true`;
                    }
                }
            });
        }

        function calculateRoute() {
            if (stopsData.length < 2) return;
            let waypoints = [];
            for (let i = 1; i < stopsData.length - 1; i++) {
                waypoints.push({
                    location: (stopsData[i].lat && stopsData[i].lng) ? new google.maps.LatLng(stopsData[i].lat, stopsData[i].lng) : { query: stopsData[i].name },
                    stopover: true
                });
            }
            const origin = stopsData[0];
            const dest = stopsData[stopsData.length - 1];
            const originLoc = (origin.lat && origin.lng) ? new google.maps.LatLng(origin.lat, origin.lng) : origin.name;
            const destLoc = (dest.lat && dest.lng) ? new google.maps.LatLng(dest.lat, dest.lng) : dest.name;

            directionsService.route({
                origin: originLoc,
                destination: destLoc,
                waypoints: waypoints,
                optimizeWaypoints: true,
                travelMode: 'DRIVING',
                drivingOptions: {
                    departureTime: new Date(),
                    trafficModel: 'bestguess'
                }
            }, (res, status) => {
                if (status === 'OK') {
                    directionsRenderer.setDirections(res);
                    buildStopSequence(res.routes[0].waypoint_order);

                    try {
                        let targetLeg = res.routes[0].legs[currentTargetIndex];
                        if (targetLeg) {
                            const liveEtaText = targetLeg.duration_in_traffic ? targetLeg.duration_in_traffic.text : targetLeg.duration.text;
                            updateLiveEtaToDatabase(liveEtaText);
                        }
                    } catch (e) { }
                }
            });
        }

        function updateLiveEtaToDatabase(etaText) {
            const docId = rawId.startsWith('SCHED:') ? rawId.substring(6) : rawId.substring(5);
            const collection = tripType === 'schedule' ? 'Schedules' : 'Bookings';
            firestore.collection(collection).doc(docId).update({
                live_eta: etaText,
                live_eta_updated_at: firebase.firestore.FieldValue.serverTimestamp()
            }).catch(e => { });
        }

        function buildStopSequence(order) {
            optimizedStops = [stopsData[0]];
            let intermediate = stopsData.slice(1, stopsData.length - 1);
            order.forEach(index => optimizedStops.push(intermediate[index]));
            optimizedStops.push(stopsData[stopsData.length - 1]);

            if (tripType === 'ondemand') {
                if (onDemandStatus === 'confirmed' || onDemandStatus === 'arriving') currentTargetIndex = 0;
                else if (onDemandStatus === 'onboard') currentTargetIndex = 1;
            } else {
                let savedIndex = localStorage.getItem('trip_progress_' + rawId);
                currentTargetIndex = savedIndex !== null ? parseInt(savedIndex) : 0;
            }
            renderUI();
        }

        function renderUI() {
            const tl = document.getElementById('timelineList');
            tl.innerHTML = '';

            optimizedStops.forEach((stop, index) => {
                let statusClass = index < currentTargetIndex ? 's-done' : (index === currentTargetIndex ? 's-active' : '');
                let check = statusClass === 's-done' ? '<i class="fas fa-check" style="position:absolute; left:-18px; top:4px; color:white; font-size:10px; z-index:2;"></i>' : '';
                tl.innerHTML += `
                <div class="stop-item ${statusClass}">
                    <div class="s-dot"></div>
                    ${check}
                    <div class="s-text">${stop.name}</div>
                    <div class="s-meta">${stop.eta}</div>
                </div>`;
            });

            updateCardHeaderState();
            updateNavUrl();
        }

        function handlePrimaryAction() {
            const btn = document.getElementById('primaryAction');
            const targetStop = optimizedStops[currentTargetIndex];

            if (currentTargetIndex === optimizedStops.length - 1 && btn.innerText.includes("FINISH TRIP")) {
                addTripLog(`Trip Completed at Final Destination`, 'system');
                localStorage.removeItem('trip_progress_' + rawId);
                setTimeout(() => { document.getElementById('finishForm').submit(); }, 300);
                return;
            }

            if (tripType === 'schedule') {
                if (btn.innerText.includes("ARRIVED")) {
                    updateCardHeaderState("BOARDING", `At ${targetStop.name}. Scan tickets.`);
                    btn.className = "btn-massive green";
                    btn.innerHTML = `FINISH BOARDING <i class="fas fa-door-closed"></i>`;
                    addTripLog(`Arrived at <b>${targetStop.name}</b>`, 'system');
                } else if (btn.innerText.includes("FINISH BOARDING")) {
                    currentTargetIndex++;
                    localStorage.setItem('trip_progress_' + rawId, currentTargetIndex);
                    addTripLog(`Departed for next stop.`, 'system');
                    renderUI();
                } else if (btn.innerText.includes("FINISH ROUTE")) {
                    localStorage.removeItem('trip_progress_' + rawId);
                    document.getElementById('finishForm').submit();
                }
            } else {
                if (onDemandStatus === 'confirmed') {
                    onDemandStatus = 'arriving';
                    addTripLog(`Arrived at Pickup.`, 'system');
                    fetch(`update_booking_status.php?id=${rawId.substring(5)}&status=arriving`);
                    renderUI();
                } else if (onDemandStatus === 'arriving') {
                    onDemandStatus = 'onboard';
                    currentTargetIndex++;
                    addTripLog(`Trip Started.`, 'system');
                    fetch(`update_booking_status.php?id=${rawId.substring(5)}&status=onboard`);
                    renderUI();
                } else {
                    document.getElementById('finishForm').submit();
                }
            }
        }

        function updateCardHeaderState(customHeader = null, customTitle = null) {
            const btn = document.getElementById('primaryAction');
            const insH = document.getElementById('insHeader');
            const insT = document.getElementById('insTitle');
            const targetStop = optimizedStops[currentTargetIndex];

            if (customHeader) {
                insH.innerText = customHeader;
                insT.innerHTML = customTitle;
                return;
            }

            if (!targetStop) {
                insH.innerText = "TRIP COMPLETE";
                insT.innerText = "All stops visited";
                btn.className = "btn-massive red";
                btn.innerHTML = `FINISH ROUTE <i class="fas fa-flag-checkered"></i>`;
                return;
            }

            if (currentTargetIndex === optimizedStops.length - 1) {
                btn.className = "btn-massive red";
                btn.innerHTML = `FINISH TRIP <i class="fas fa-flag-checkered"></i>`;
            } else if (tripType === 'ondemand') {
                if (currentTargetIndex === 0) {
                    btn.className = "btn-massive blue";
                    btn.innerHTML = `ARRIVED AT PICKUP <i class="fas fa-map-marker-alt"></i>`;
                } else if (onDemandStatus === 'arriving') {
                    btn.className = "btn-massive green";
                    btn.innerHTML = `START TRIP <i class="fas fa-play"></i>`;
                } else {
                    btn.className = "btn-massive blue";
                    btn.innerHTML = `ARRIVED AT DROPOFF <i class="fas fa-flag-checkered"></i>`;
                }
            } else {
                btn.className = "btn-massive blue";
                btn.innerHTML = `ARRIVED AT STOP <i class="fas fa-map-marker-alt"></i>`;
            }

            if (currentSnapState === 'collapsed') {
                insH.innerText = "ON THE WAY TO: " + targetStop.name.toUpperCase();
                insT.innerHTML = " ";
            } else {
                if (currentTargetIndex === optimizedStops.length - 1) {
                    insH.innerText = "FINAL STOP";
                    insT.innerText = targetStop.name;
                } else if (tripType === 'ondemand') {
                    if (currentTargetIndex === 0) {
                        insH.innerText = "DRIVE TO PICKUP";
                        insT.innerHTML = `<i class="fas fa-user"></i> Pick up: ${passengerName}`;
                    } else if (onDemandStatus === 'arriving') {
                        insH.innerText = "AT PICKUP";
                        insT.innerHTML = `Wait for ${passengerName} to board.`;
                    } else {
                        insH.innerText = "DRIVE TO DROPOFF";
                        insT.innerText = targetStop.name;
                    }
                } else {
                    insH.innerText = "NEXT STOP";
                    insT.innerText = targetStop.name;
                }
            }
        }

        function updateNavUrl() {
            if (optimizedStops.length < 2 || currentTargetIndex >= optimizedStops.length) return;
            const btn = document.getElementById('navBtn');
            let destStop = optimizedStops[currentTargetIndex];

            let destLoc = (destStop.lat && destStop.lng) ? `${destStop.lat},${destStop.lng}` : encodeURIComponent(destStop.name);
            btn.href = `https://www.google.com/maps/dir/?api=1&destination=${destLoc}&travelmode=driving`;

            btn.onclick = function () {
                addTripLog(`Navigation started to <b>${destStop.name}</b>`, 'system');
            };
        }

        function switchCardTab(tabName) {
            document.getElementById('tabBtn-route').classList.remove('active');
            document.getElementById('tabBtn-log').classList.remove('active');
            document.getElementById('tabContent-route').style.display = 'none';
            document.getElementById('tabContent-log').style.display = 'none';

            document.getElementById('tabBtn-' + tabName).classList.add('active');
            document.getElementById('tabContent-' + tabName).style.display = 'block';
        }

        function renderLogToDOM(message, type, isoString) {
            const container = document.getElementById('tripLogContainer');
            const d = new Date(isoString);
            const timeStr = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
            let typeClass = type === 'in' ? 'check-in' : (type === 'out' ? 'check-out' : 'system-log');
            container.insertAdjacentHTML('afterbegin', `
                <div class="log-entry ${typeClass}">
                    <div class="log-time">${timeStr}</div><div class="log-msg">${message}</div>
                </div>`);
        }

        function loadTripLogs() {
            const docId = rawId.startsWith('SCHED:') ? rawId.substring(6) : rawId.substring(5);
            const collection = tripType === 'schedule' ? 'Schedules' : 'Bookings';

            firestore.collection(collection).doc(docId).get().then(doc => {
                if (doc.exists) {
                    const data = doc.data();
                    if (data.trip_logs && data.trip_logs.length > 0) {
                        const container = document.getElementById('tripLogContainer');
                        container.innerHTML = '';
                        data.trip_logs.forEach(log => {
                            renderLogToDOM(log.message, log.type, log.timestamp);
                        });
                    } else {
                        addTripLog('Trip Engine Initialized', 'system');
                    }
                }
            }).catch(err => console.error("Error loading logs:", err));
        }

        function addTripLog(message, type = 'system') {
            const isoString = new Date().toISOString();
            renderLogToDOM(message, type, isoString);

            const docId = rawId.startsWith('SCHED:') ? rawId.substring(6) : rawId.substring(5);
            const collection = tripType === 'schedule' ? 'Schedules' : 'Bookings';

            firestore.collection(collection).doc(docId).update({
                trip_logs: firebase.firestore.FieldValue.arrayUnion({
                    message: message,
                    type: type,
                    timestamp: isoString
                })
            }).catch(err => console.error("Error saving log:", err));
        }

        function haversineDist(lat1, lon1, lat2, lon2) {
            const R = 6371e3;
            const p1 = lat1 * Math.PI / 180; const p2 = lat2 * Math.PI / 180;
            const dp = (lat2 - lat1) * Math.PI / 180; const dl = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        let lastLocationUpdate = 0;

        function trackLocation() {
            if ("geolocation" in navigator) {
                navigator.geolocation.watchPosition((position) => {
                    const lat = position.coords.latitude; const lng = position.coords.longitude;
                    if (!currentLocation) map.panTo({ lat, lng });
                    currentLocation = { lat, lng };

                    const now = Date.now();
                    if (now - lastLocationUpdate > 5000) {
                        const fd = new FormData();
                        fd.append('driver_id', "<?= $driverId ?>"); fd.append('lat', lat); fd.append('lng', lng);
                        if (navigator.sendBeacon) navigator.sendBeacon('update_location.php', fd);
                        else fetch('update_location.php', { method: 'POST', body: fd });
                        lastLocationUpdate = now;
                    }

                    checkGeofence();
                }, null, { enableHighAccuracy: true, maximumAge: 0, timeout: 5000 });

                setInterval(() => { if (currentLocation) map.panTo(currentLocation); }, 15000);
                setInterval(() => { calculateRoute(); }, 180000);
            }
        }

        function checkGeofence() {
            if (currentTargetIndex >= optimizedStops.length) return;
            const target = optimizedStops[currentTargetIndex];
            if (!target.lat || !target.lng) return;

            const dist = haversineDist(currentLocation.lat, currentLocation.lng, parseFloat(target.lat), parseFloat(target.lng));
            const btn = document.getElementById('primaryAction');
            if (dist <= 50 && btn.innerText.includes("ARRIVED")) {
                showToast("Auto-Arrived at " + target.name);
                handlePrimaryAction();
            }
        }

        let html5QrcodeScanner;
        function startScanner() {
            document.getElementById('scanModal').style.display = 'flex';
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess, (e) => { });
        }
        function stopScanner() {
            if (html5QrcodeScanner) html5QrcodeScanner.stop().catch(e => { });
            document.getElementById('scanModal').style.display = 'none';
        }
        function showToast(msg, isError = false) {
            const t = document.getElementById('toast');
            document.getElementById('toastMsg').innerText = msg;
            t.style.background = isError ? '#e74c3c' : '#2ecc71';
            t.style.opacity = '1';
            setTimeout(() => { t.style.opacity = '0'; }, 3000);
        }

        function onScanSuccess(decodedText) {
            html5QrcodeScanner.pause();
            let scannedBid = decodedText.trim();
            try {
                if (decodedText.startsWith('{') || decodedText.startsWith('[')) {
                    const jsonObj = JSON.parse(decodedText);
                    if (jsonObj.bid) scannedBid = jsonObj.bid;
                }
            } catch (e) { }

            if (tripType === 'ondemand') {
                const bookingId = rawId.substring(5);
                if (scannedBid.includes(bookingId)) {
                    showToast("Boarded: " + passengerName);
                    addTripLog(`<b>${passengerName}</b> boarded the shuttle.`, 'in');
                    stopScanner();
                    onDemandStatus = 'onboard'; currentTargetIndex++;
                    fetch(`update_booking_status.php?id=${bookingId}&status=onboard`);
                    renderUI();
                } else {
                    showToast("Invalid QR", true);
                    html5QrcodeScanner.resume();
                }
            } else {
                fetch('verify_ticket.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'booking_id=' + encodeURIComponent(scannedBid) + '&schedule_id=' + encodeURIComponent(rawId.substring(6))
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast("Boarded: " + data.student_name);
                            addTripLog(`<b>${data.student_name}</b> checked in.`, 'in');
                            if (document.getElementById('uiOnboard')) document.getElementById('uiOnboard').innerText = data.new_count;
                        } else {
                            showToast(data.message, true);
                        }
                        stopScanner();
                    }).catch(err => { showToast("Network Error", true); stopScanner(); });
            }
        }

        const controlCard = document.getElementById('controlCard');
        const dragArea = document.createElement('div');
        dragArea.style.position = 'absolute';
        dragArea.style.top = '0'; dragArea.style.left = '0';
        dragArea.style.width = '100%'; dragArea.style.height = '50px';
        dragArea.style.zIndex = '100';
        controlCard.appendChild(dragArea);

        let isDraggingCard = false;
        let dragStartY = 0;
        let cardStartHeight = 0;
        let currentSnapState = 'collapsed';

        function snapToHeight(percentage) {
            controlCard.style.transition = 'height 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
            controlCard.style.height = percentage + '%';

            const qrBtn = document.querySelector('.qr-btn');
            if (qrBtn) {
                qrBtn.style.transition = 'bottom 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                qrBtn.style.bottom = `calc(${percentage}% + 20px)`;
            }

            currentSnapState = (percentage <= 30) ? 'collapsed' : 'expanded';
            updateCardHeaderState();

            setTimeout(() => {
                controlCard.style.transition = 'none';
                if (qrBtn) qrBtn.style.transition = 'none';
            }, 300);
        }

        function onTouchStart(e) {
            isDraggingCard = true;
            dragStartY = e.touches ? e.touches[0].clientY : e.clientY;
            cardStartHeight = controlCard.getBoundingClientRect().height;
            controlCard.style.transition = 'none';
            const qrBtn = document.querySelector('.qr-btn');
            if (qrBtn) qrBtn.style.transition = 'none';
        }

        function onTouchMove(e) {
            if (!isDraggingCard) return;
            let clientY = e.touches ? e.touches[0].clientY : e.clientY;
            let delta = dragStartY - clientY;
            let newPx = cardStartHeight + delta;
            let percent = (newPx / window.innerHeight) * 100;

            if (percent > 85) percent = 85;
            if (percent < 25) percent = 25;

            controlCard.style.height = percent + '%';
            const qrBtn = document.querySelector('.qr-btn');
            if (qrBtn) qrBtn.style.bottom = `calc(${percent}% + 20px)`;

            e.preventDefault();
        }

        function onTouchEnd(e) {
            if (!isDraggingCard) return;
            isDraggingCard = false;
            let finalHeightPx = controlCard.getBoundingClientRect().height;
            let p = (finalHeightPx / window.innerHeight) * 100;

            let snap = 25;
            if (p > 55) snap = 85;
            else if (p > 35) snap = 50;
            else snap = 25;

            snapToHeight(snap);
        }

        dragArea.addEventListener('touchstart', onTouchStart, { passive: false });
        dragArea.addEventListener('touchmove', onTouchMove, { passive: false });
        dragArea.addEventListener('touchend', onTouchEnd);

        setTimeout(() => snapToHeight(25), 100);
    </script>
</body>

</html>