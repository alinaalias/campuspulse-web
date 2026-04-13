<?php
session_start();
require_once '../config.php';

// 1. Security & Identity Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 2. FETCH DRIVER'S VEHICLE (Required for GPS Sync)
$driverSnap = $firestore->database()->collection('Staffs')->document($driverId)->snapshot();
$shuttleId = $driverSnap->data()['assigned_shuttle_id'] ?? 'UNKNOWN';

// 3. FETCH ACTIVE MISSIONS (Pickup & Dropoff)
$bookingsRef = $firestore->database()->collection('Bookings')
    ->where('driver_id', '=', $driverId)
    ->where('type', '=', 'ondemand')
    ->where('status', 'in', ['confirmed', 'arriving', 'onboard']);

$documents = $bookingsRef->documents();
$missionData = [];

foreach ($documents as $doc) {
    if (!$doc->exists())
        continue;
    $data = $doc->data();
    $id = $doc->id();
    $status = $data['status'];

    // Resolve Student Name
    $sName = "Student";
    if (!empty($data['user_id'])) {
        $stSnap = $firestore->database()->collection('Students')->document($data['user_id'])->snapshot();
        if ($stSnap->exists())
            $sName = $stSnap->data()['full_name'] ?? "Student";
    }

    // Determine target stop based on current state
    $stopId = (in_array($status, ['confirmed', 'arriving'])) ? $data['pickup_stop_id'] : $data['dropoff_stop_id'];
    $targetType = (in_array($status, ['confirmed', 'arriving'])) ? 'pickup' : 'dropoff';

    // Fetch Coordinates from Stops
    $lat = 0;
    $lng = 0;
    $stopName = "Unknown";
    if ($stopId) {
        $stSnap = $firestore->database()->collection('Stops')->document($stopId)->snapshot();
        if ($stSnap->exists()) {
            $stData = $stSnap->data();
            $lat = $stData['lat'] ?? $stData['latitude'] ?? 0;
            $lng = $stData['lng'] ?? $stData['longitude'] ?? 0;
            $stopName = $stData['name'] ?? $stData['stop_name'] ?? $stopId;
        }
    }

    if ($lat != 0) {
        $missionData[] = [
            'booking_id' => $id,
            'type' => $targetType,
            'student' => $sName,
            'address' => $stopName,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'status' => $status
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Active Missions - CampusPulse</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        #map {
            width: 100%;
            height: 50vh;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .mission-container {
            padding: 25px 20px;
            background: #fff;
            border-radius: 30px 30px 0 0;
            margin-top: -30px;
            position: relative;
            z-index: 10;
            min-height: 55vh;
            box-shadow: 0 -15px 40px rgba(0, 0, 0, 0.15);
        }

        .sheet-drag-handle {
            width: 50px;
            height: 5px;
            background: #dee2e6;
            border-radius: 5px;
            margin: -10px auto 20px auto;
        }

        .back-btn-overlay {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.95);
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #333;
            z-index: 50;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            text-decoration: none;
            transition: transform 0.2s;
        }

        .back-btn-overlay:active {
            transform: scale(0.9);
        }

        .stop-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #eee;
            transition: transform 0.2s;
        }

        .stop-card.active-next {
            border: 2px solid var(--primary-blue);
            background: #f0f7ff;
        }

        .indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
        }

        .pickup-dot {
            background: #2ecc71;
        }

        .dropoff-dot {
            background: #e74c3c;
        }

        .btn-action {
            padding: 10px 18px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-pickup {
            background: #2ecc71;
        }

        .btn-dropoff {
            background: #e74c3c;
        }

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
            border-radius: 12px;
            overflow: hidden;
        }
    </style>
</head>

<body style="background:#f4f7f9; margin:0;">

    <a href="driver_dashboard.php" class="back-btn-overlay">
        <i class="fas fa-arrow-left"></i>
    </a>

    <div id="map"></div>

    <div class="mission-container">
        <div class="sheet-drag-handle"></div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">Active Mission Pool</h3>
            <span style="background:#eee; padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:600;">
                SHUTTLE: <?= $shuttleId ?>
            </span>
        </div>

        <div id="mission-list">
        </div>
    </div>

    <div id="scanModal">
        <div style="position:absolute; top:20px; right:20px; color:white; font-size:2rem;" onclick="stopScanner()">×
        </div>
        <h3 style="color:white; margin-bottom:20px;">Scan Ticket</h3>
        <div id="reader"></div>
    </div>

    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAhJXLX5-6MTNRXHoutsSrZWI99BJCjeo4&callback=initMap"
        async defer></script>

    <script>
        const missions = <?= json_encode($missionData) ?>;
        const shuttleId = "<?= $shuttleId ?>";
        let map, directionsService, directionsRenderer, html5QrcodeScanner;
        let currentScanBookingId = null;

        function initMap() {
            directionsService = new google.maps.DirectionsService();
            directionsRenderer = new google.maps.DirectionsRenderer({ map: map, suppressMarkers: false });

            map = new google.maps.Map(document.getElementById("map"), {
                zoom: 15, center: { lat: 3.1592, lng: 101.7036 }, disableDefaultUI: true,
                styles: [{ featureType: "poi", stylers: [{ visibility: "off" }] }]
            });
            directionsRenderer.setMap(map);

            calculateRoute();
            startLocationTracking(); // CRITICAL: This starts the "Sync"
        }

        // 1. GPS HEARTBEAT: Updates the Shuttle collection for Live Operations
        function startLocationTracking() {
            if (navigator.geolocation) {
                navigator.geolocation.watchPosition(pos => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;

                    // Update Firestore via a small helper script
                    const formData = new FormData();
                    formData.append('shuttle_id', shuttleId);
                    formData.append('lat', lat);
                    formData.append('lng', lng);
                    fetch('update_location.php', { method: 'POST', body: formData });

                }, null, { enableHighAccuracy: true });
            }
        }

        function calculateRoute() {
            if (missions.length === 0) {
                document.getElementById('mission-list').innerHTML = "<p style='text-align:center; color:#999;'>No active jobs assigned.</p>";
                return;
            }

            const waypoints = missions.slice(0, -1).map(m => ({
                location: { lat: m.lat, lng: m.lng }, stopover: true
            }));

            directionsService.route({
                origin: "current location", // Or driver's current lat/lng
                destination: { lat: missions[missions.length - 1].lat, lng: missions[missions.length - 1].lng },
                waypoints: waypoints,
                optimizeWaypoints: true,
                travelMode: 'DRIVING'
            }, (res, status) => {
                if (status === 'OK') {
                    directionsRenderer.setDirections(res);
                    renderUIList(res.routes[0].waypoint_order);
                }
            });
        }

        function renderUIList(optimizedOrder) {
            const list = document.getElementById('mission-list');
            list.innerHTML = "";

            // Re-order missions based on Google's TSP optimization
            let ordered = [];
            if (missions.length > 1) {
                optimizedOrder.forEach(idx => ordered.push(missions[idx]));
                ordered.push(missions[missions.length - 1]);
            } else {
                ordered = missions;
            }

            ordered.forEach((m, i) => {
                const isActive = (i === 0); // Highlight the next immediate stop
                const btn = m.type === 'pickup'
                    ? `<button class="btn-action btn-pickup" onclick="openScanner('${m.booking_id}')"><i class="fas fa-qrcode"></i> Pickup</button>`
                    : `<button class="btn-action btn-dropoff" onclick="handleDropoff('${m.booking_id}')"><i class="fas fa-check"></i> Finish</button>`;

                list.innerHTML += `
                <div class="stop-card ${isActive ? 'active-next' : ''}">
                    <div style="display:flex; align-items:center;">
                        <div class="indicator ${m.type}-dot"></div>
                        <div>
                            <div style="font-size:0.7rem; font-weight:700; color:#aaa;">STOP ${i + 1} • ${m.type.toUpperCase()}</div>
                            <div style="font-weight:700; color:#333;">${m.student}</div>
                            <div style="font-size:0.8rem; color:#666;"><i class="fas fa-map-marker-alt"></i> ${m.address}</div>
                        </div>
                    </div>
                    ${btn}
                </div>
            `;
            });
        }

        // --- Actions ---
        function openScanner(id) {
            currentScanBookingId = id;
            document.getElementById('scanModal').style.display = 'flex';
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess);
        }

        function onScanSuccess(decodedText) {
            // Match scanned QR with the selected Booking ID
            if (decodedText.includes(currentScanBookingId)) {
                stopScanner();
                alert("Boarding Confirmed!");
                updateStatus(currentScanBookingId, 'onboard');
            } else {
                alert("Verification Failed: Invalid QR for this passenger.");
            }
        }

        function stopScanner() {
            if (html5QrcodeScanner) html5QrcodeScanner.stop();
            document.getElementById('scanModal').style.display = 'none';
        }

        function handleDropoff(id) {
            if (confirm("Complete ride for this student?")) {
                updateStatus(id, 'completed');
            }
        }

        function updateStatus(id, status) {
            fetch(`update_booking_status.php?id=${id}&status=${status}`)
                .then(() => window.location.reload());
        }
    </script>
</body>

</html>