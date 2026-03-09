<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../config.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$scheduleId = $_GET['id'] ?? '';
if (!$scheduleId) {
    header('Location: driver_dashboard.php');
    exit();
}

// 2. Fetch Trip Data
$schedRef = $firestore->database()->collection('Schedules')->document($scheduleId);
$schedSnap = $schedRef->snapshot();
if (!$schedSnap->exists()) { echo "Trip not found."; exit(); }
$trip = $schedSnap->data();

// 3. DATE ROBUSTNESS CHECK
$today = date('Y-m-d');
if ($trip['date'] !== $today) {
    echo "<script>alert('Cannot start trip: Scheduled for " . $trip['date'] . "'); window.location.href='driver_dashboard.php';</script>";
    exit();
}

// 4. Fetch Route & Stops (Logic identical to previous)
$routeRef = $firestore->database()->collection('Routes')->document($trip['route_id']);
$routeSnap = $routeRef->snapshot();
$stopsData = []; 

if ($routeSnap->exists()) {
    $rData = $routeSnap->data();
    $stopIds = $rData['stop_ids'] ?? [];
    if (empty($stopIds)) $stopIds = [$trip['start_stop_id'], $trip['end_stop_id']];

    $scheduledStart = $trip['start_stop_id'];
    $scheduledEnd   = $trip['end_stop_id'];
    
    if (!empty($stopIds) && (end($stopIds) === $scheduledStart || reset($stopIds) === $scheduledEnd)) {
        $stopIds = array_reverse($stopIds);
    }

    foreach ($stopIds as $sid) {
        $sSnap = $firestore->database()->collection('Stops')->document($sid)->snapshot();
        $name = $sid;
        $lat = null;
        $lng = null;
        
        if ($sSnap->exists()) {
            $d = $sSnap->data();
            $name = $d['stop_name'] ?? $d['name'] ?? $sid;
            $lat = $d['latitude'] ?? $d['lat'] ?? null;
            $lng = $d['longitude'] ?? $d['lng'] ?? null;
        }
        $stopsData[] = ['id' => $sid, 'name' => $name, 'lat' => $lat, 'lng' => $lng];
    }
}

$onboard  = $trip['onboard_count'] ?? 0;
$booked = $trip['booked_count'] ?? 0;
$capacity = $trip['capacity'] ?? 13;
$seatsLeft = max(0, $capacity - $booked);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Active Trip</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <style>
        #map { width: 100%; height: 350px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn-nav-external { display: block; width: 100%; padding: 12px; background: white; color: #4285F4; border: 1px solid #ddd; border-radius: 8px; text-align: center; font-weight: 600; margin-bottom: 20px; text-decoration: none; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .timeline { position: relative; padding-left: 30px; margin: 10px 0; }
        .timeline::before { content: ''; position: absolute; left: 10px; top: 5px; bottom: 5px; width: 2px; background: #e0e0e0; z-index: 0; }
        .stop-item { position: relative; margin-bottom: 25px; transition: all 0.3s; }
        .stop-dot { position: absolute; left: -30px; top: 2px; width: 20px; height: 20px; border-radius: 50%; background: white; border: 4px solid var(--primary-blue); z-index: 2; }
        .stop-item:last-child .stop-dot { border-color: var(--accent-yellow); }
        #scanModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; }
        #reader { width: 300px; height: 300px; background: black; }
        .close-scan { position: absolute; top: 20px; right: 20px; color: white; font-size: 2rem; cursor: pointer; }
        .btn-scan { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); z-index: 999; background-color: #2c3e50; color: white; border: none; border-radius: 50px; padding: 15px 40px; font-size: 1.1rem; font-weight: 600; box-shadow: 0 4px 15px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 10px; cursor: pointer; }
        /* New: Log Style */
        #logContainer { max-height: 100px; overflow-y: auto; background: #f9f9f9; padding: 10px; border-radius: 8px; margin-top: 15px; border: 1px dashed #ddd; }
        .log-item { font-size: 0.85rem; color: #333; margin-bottom: 5px; border-bottom: 1px solid #eee; padding-bottom: 2px; }
    </style>
</head>
<body class="driver-body">

    <div class="driver-header">
        <a href="driver_dashboard.php" style="color:white; text-decoration:none;"><i class="fas fa-arrow-left"></i></a>
        <div style="font-weight:600; font-size:1.1rem;">Trip Navigation</div>
        <div style="width:20px;"></div>
    </div>

    <div class="driver-container">
        <div id="map"></div>
        <a id="googleMapsLink" href="#" target="_blank" class="btn-nav-external"><i class="fas fa-location-arrow"></i> Open in Google Maps App</a>

        <div class="driver-card" style="margin-top:0;">
            <div style="display:flex; justify-content:space-between; text-align:center;">
                <div style="flex:1;">
                    <div id="onboardCount" style="font-size:1.4rem; font-weight:700; color:var(--primary-blue);"><?= $onboard ?></div>
                    <div style="font-size:0.65rem; color:#888; font-weight:600;">ONBOARD</div>
                </div>
                <div style="flex:1; border-left:1px solid #eee;">
                    <div style="font-size:1.4rem; font-weight:700; color:#f57c00;"><?= $booked ?></div>
                    <div style="font-size:0.65rem; color:#888; font-weight:600;">BOOKED</div>
                </div>
                <div style="flex:1; border-left:1px solid #eee;">
                    <div style="font-size:1.4rem; font-weight:700; color:var(--success);"><?= $seatsLeft ?></div>
                    <div style="font-size:0.65rem; color:#888; font-weight:600;">SEATS LEFT</div>
                </div>
            </div>
            
            <div id="logContainer">
                <div style="font-size:0.75rem; color:#aaa;">Recent Check-ins:</div>
            </div>
        </div>

        <h3 style="margin:15px 0 10px; font-size:1rem; color:#555;">Optimized Route</h3>
        <div class="driver-card">
            <div class="timeline" id="stopsList">
                <p style="color:#999; font-size:0.9rem; padding:10px;">Initializing...</p>
            </div>
        </div>

        <button onclick="startScanner()" class="btn-scan"><i class="fas fa-qrcode"></i> Scan Ticket</button>
    </div>

    <div id="scanModal">
        <div class="close-scan" onclick="stopScanner()">×</div>
        <h3 style="color:white; margin-bottom:20px;">Scan Ticket</h3>
        <div id="reader"></div>
    </div>

    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBnjGNcxW0UPWgfG8S7OZP2PEra22BzwDg&libraries=places&callback=initMap" async defer></script>

    <script>
    const rawStops = <?= json_encode($stopsData) ?>;
    let map, directionsService, directionsRenderer, html5QrcodeScanner;

    function initMap() {
        const kl = { lat: 3.1390, lng: 101.6869 };
        map = new google.maps.Map(document.getElementById("map"), { zoom: 12, center: kl, disableDefaultUI: true });
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer({ map: map, suppressMarkers: false });
        calculateRoute();
        trackLocation();
    }

    function calculateRoute() {
        if (rawStops.length < 2) return;
        const originData = rawStops[0];
        const destinationData = rawStops[rawStops.length - 1];
        const waypoints = [];
        for (let i = 1; i < rawStops.length - 1; i++) {
            let loc = (rawStops[i].lat && rawStops[i].lng) ? new google.maps.LatLng(rawStops[i].lat, rawStops[i].lng) : { query: rawStops[i].name };
            waypoints.push({ location: loc, stopover: true });
        }
        const startLoc = (originData.lat && originData.lng) ? new google.maps.LatLng(originData.lat, originData.lng) : originData.name;
        const endLoc = (destinationData.lat && destinationData.lng) ? new google.maps.LatLng(destinationData.lat, destinationData.lng) : destinationData.name;

        directionsService.route({
            origin: startLoc, destination: endLoc, waypoints: waypoints, optimizeWaypoints: true, travelMode: google.maps.TravelMode.DRIVING,
        }, (response, status) => {
            if (status === "OK") {
                directionsRenderer.setDirections(response);
                updateStopList(response.routes[0].waypoint_order);
                generateDeepLink(startLoc, endLoc, waypoints, response.routes[0].waypoint_order);
            }
        });
    }

    function updateStopList(order) {
        let optimizedStops = [];
        optimizedStops.push(rawStops[0]);
        let intermediate = rawStops.slice(1, rawStops.length - 1);
        order.forEach(index => { optimizedStops.push(intermediate[index]); });
        optimizedStops.push(rawStops[rawStops.length - 1]);

        const listDiv = document.getElementById('stopsList');
        listDiv.innerHTML = '';
        optimizedStops.forEach((stop, index) => {
            let label = (index === 0) ? "Start Point" : ((index === optimizedStops.length - 1) ? "Destination" : "Stop " + index);
            let dotColor = (index === optimizedStops.length - 1) ? "var(--accent-yellow)" : "var(--primary-blue)";
            listDiv.innerHTML += `
                <div class="stop-item">
                    <div class="stop-dot" style="border-color:${dotColor}"></div>
                    <div style="font-weight:600; font-size:0.95rem;">${stop.name}</div>
                    <div style="font-size:0.8rem; color:#888;">${label}</div>
                </div>`;
        });
    }

    function generateDeepLink(origin, dest, waypoints, order) {
        let baseUrl = "https://www.google.com/maps/dir/?api=1";
        const getLocString = (loc) => (typeof loc === 'string') ? encodeURIComponent(loc) : loc.lat() + "," + loc.lng();
        let url = `${baseUrl}&origin=${getLocString(origin)}&destination=${getLocString(dest)}`;
        if (order.length > 0) {
            let wpStrings = [];
            order.forEach(index => {
                let loc = waypoints[index].location;
                if(loc.query) wpStrings.push(encodeURIComponent(loc.query));
                else wpStrings.push(loc.lat() + "," + loc.lng());
            });
            url += `&waypoints=${wpStrings.join('|')}&travelmode=driving`;
        }
        document.getElementById('googleMapsLink').href = url;
    }

    function trackLocation() {
        if ("geolocation" in navigator) {
            navigator.geolocation.watchPosition((position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const scheduleId = "<?= $scheduleId ?>";
                const formData = new FormData();
                formData.append('schedule_id', scheduleId);
                formData.append('lat', lat);
                formData.append('lng', lng);
                if (navigator.sendBeacon) navigator.sendBeacon('update_location.php', formData);
                else fetch('update_location.php', { method: 'POST', body: formData });
            }, null, { enableHighAccuracy: true, maximumAge: 0, timeout: 5000 });
        }
    }

    function startScanner() {
        document.getElementById('scanModal').style.display = 'flex';
        html5QrcodeScanner = new Html5Qrcode("reader");
        html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess, (e) => {});
    }
    
    // --- FIX: Scanner Logic Update ---
    
    function stopScanner() {
        if(html5QrcodeScanner) {
            html5QrcodeScanner.stop().then(() => {
                document.getElementById('scanModal').style.display = 'none';
            }).catch(err => {
                // If it fails to stop (e.g. active), just hide the modal
                document.getElementById('scanModal').style.display = 'none';
            });
        } else {
            document.getElementById('scanModal').style.display = 'none';
        }
    }
    
    function onScanSuccess(decodedText) {
        // 1. Pause first so it doesn't keep scanning
        html5QrcodeScanner.pause();

        // 2. PARSE THE QR CODE JSON
        let bookingId = decodedText;
        try {
            if (decodedText.startsWith('{') || decodedText.startsWith('[')) {
                const jsonObj = JSON.parse(decodedText);
                if (jsonObj.bid) {
                    bookingId = jsonObj.bid;
                }
            }
        } catch (e) {
            console.log("Not a JSON QR, using raw text:", e);
        }

        // 3. Verify with Server
        verifyTicket(bookingId);
    }
    
    function verifyTicket(ticketId) {
        const scheduleId = "<?= $scheduleId ?>";
        const cleanId = ticketId.trim();

        fetch('verify_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'booking_id=' + encodeURIComponent(cleanId) + '&schedule_id=' + encodeURIComponent(scheduleId)
        })
        .then(res => res.json())
        .then(data => {
            // 4. Update UI FIRST (don't wait for camera to stop)
            if(data.success) {
                alert("✅ " + data.student_name + " Checked In!");
                document.getElementById('onboardCount').innerText = data.new_count;
                const log = document.getElementById('logContainer');
                log.innerHTML += `<div class="log-item"><b>${data.student_name}</b> checked in.</div>`;
            } else {
                alert("❌ " + data.message);
            }
            
            // 5. Cleanup Camera
            stopScanner();
        })
        .catch(err => {
            alert("Network Error");
            stopScanner();
        });
    }
    </script>
</body>
</html>