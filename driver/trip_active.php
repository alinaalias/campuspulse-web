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
if (!$schedSnap->exists()) {
    echo "Trip not found.";
    exit();
}
$trip = $schedSnap->data();

// 3. DATE ROBUSTNESS CHECK
$today = date('Y-m-d');
if ($trip['date'] !== $today) {
    echo "<script>alert('Cannot start trip: Scheduled for " . $trip['date'] . "'); window.location.href='driver_dashboard.php';</script>";
    exit();
}

$routeRef = $firestore->database()->collection('Routes')->document($trip['route_id']);
$routeSnap = $routeRef->snapshot();
$rData = $routeSnap->exists() ? $routeSnap->data() : [];
$stopsData = [];
$etas = $trip['etas'] ?? [];

if (!empty($etas)) {
    // 1. Dynamic ETA-based routing
    asort($etas);
    $stopIds = array_keys($etas);
} else {
    // 2. Legacy Static Routing
    $stopIds = $rData['stop_ids'] ?? [];
    if (empty($stopIds))
        $stopIds = [$trip['start_stop_id'], $trip['end_stop_id']];

    $scheduledStart = $trip['start_stop_id'];
    $scheduledEnd = $trip['end_stop_id'];

    if (!empty($stopIds) && (end($stopIds) === $scheduledStart || reset($stopIds) === $scheduledEnd)) {
        $stopIds = array_reverse($stopIds);
    }
}

foreach ($stopIds as $sid) {
    $sSnap = $firestore->database()->collection('Stops')->document($sid)->snapshot();
    $name = $sid;
    $lat = null;
    $lng = null;

    if ($sSnap->exists()) {
        $d = $sSnap->data();
        $name = $d['stop_name'] ?? $d['name'] ?? $sid;

        // Prepend ETA formatting if it exists for this stop
        if (!empty($etas) && isset($etas[$sid])) {
            $name = $etas[$sid] . ' - ' . $name;
        }

        $lat = $d['latitude'] ?? $d['lat'] ?? null;
        $lng = $d['longitude'] ?? $d['lng'] ?? null;
    }
    $stopsData[] = ['id' => $sid, 'name' => $name, 'lat' => $lat, 'lng' => $lng];
}

$onboard = $trip['onboard_count'] ?? 0;
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
        #map {
            width: 100%;
            height: 350px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-nav-external {
            display: block;
            width: 100%;
            padding: 12px;
            background: white;
            color: #4285F4;
            border: 1px solid #ddd;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 20px;
            text-decoration: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .timeline {
            position: relative;
            padding-left: 30px;
            margin: 10px 0;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 5px;
            bottom: 5px;
            width: 2px;
            background: #e0e0e0;
            z-index: 0;
        }

        .stop-item {
            position: relative;
            margin-bottom: 25px;
            transition: all 0.3s;
        }

        .stop-dot {
            position: absolute;
            left: -30px;
            top: 2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            border: 4px solid var(--primary-blue);
            z-index: 2;
        }

        .stop-item:last-child .stop-dot {
            border-color: var(--accent-yellow);
        }

        #scanModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        #reader {
            width: 300px;
            height: 300px;
            background: black;
        }

        .close-scan {
            position: absolute;
            top: 20px;
            right: 20px;
            color: white;
            font-size: 2rem;
            cursor: pointer;
        }

        .btn-scan {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999;
            background-color: #2c3e50;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        /* New: Log Style */
        #logContainer {
            max-height: 100px;
            overflow-y: auto;
            background: #f9f9f9;
            padding: 10px;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px dashed #ddd;
        }

        .log-item {
            font-size: 0.85rem;
            color: #333;
            margin-bottom: 5px;
            border-bottom: 1px solid #eee;
            padding-bottom: 2px;
        }
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
        <a id="googleMapsLink" href="#" target="_blank" class="btn-nav-external"><i class="fas fa-location-arrow"></i>
            Open in Google Maps App</a>

        <div class="driver-card" style="margin-top:0;">
            <div style="display:flex; justify-content:space-between; text-align:center;">
                <div style="flex:1;">
                    <div id="onboardCount" style="font-size:1.4rem; font-weight:700; color:var(--primary-blue);">
                        <?= $onboard ?>
                    </div>
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

        <?php if (($trip['status'] ?? '') === 'active'): ?>
            <div style="margin-top:20px; text-align:center; padding-bottom: 20px;">
                <form id="standardEndForm" action="finish_trip.php" method="POST" style="display:inline-block; width:100%;">
                    <input type="hidden" name="schedule_id" value="<?= htmlspecialchars($scheduleId) ?>">
                    <button type="button" onclick="triggerEndTrip()" class="btn-save" style="background:#dc3545; width:100%;">
                        <i class="fas fa-flag-checkered"></i> Finish Trip
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <button onclick="startScanner()" class="btn-scan"><i class="fas fa-qrcode"></i> Scan Ticket</button>
    </div>

    <!-- Scan Modal -->
    <div id="scanModal">
        <div class="close-scan" onclick="stopScanner()">×</div>
        <h3 style="color:white; margin-bottom:20px;">Scan Ticket</h3>
        <div id="reader"></div>
    </div>

    <!-- Early Termination Modal -->
    <div id="terminationModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:white; padding:25px; border-radius:15px; width:90%; max-width:400px; font-family:'Poppins', sans-serif;">
            <h3 style="margin-top:0; color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> Early Termination</h3>
            <p style="font-size:0.9rem; color:#555; margin-bottom:20px;">You are attempting to end the trip outside the destination area. Please select a reason:</p>
            
            <form id="terminationForm" action="finish_trip.php" method="POST">
                <input type="hidden" name="schedule_id" value="<?= htmlspecialchars($scheduleId) ?>">
                
                <label style="font-size:0.85rem; font-weight:600; color:#444;">Select Reason</label>
                <select id="reasonSelect" name="termination_reason" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; margin-top:5px; margin-bottom:15px; font-family:inherit;" required onchange="toggleOtherReason()">
                    <option value="" disabled selected>-- Choose Reason --</option>
                    <option value="Vehicle Breakdown">Vehicle Breakdown</option>
                    <option value="Accident">Accident</option>
                    <option value="Medical Emergency">Medical Emergency</option>
                    <option value="Heavy Traffic / Rerouted">Heavy Traffic / Rerouted</option>
                    <option value="Other">Other</option>
                </select>
                
                <div id="otherReasonContainer" style="display:none; margin-bottom:20px;">
                    <label style="font-size:0.85rem; font-weight:600; color:#444;">Please specify (Required)</label>
                    <input type="text" id="otherReasonInput" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; margin-top:5px; font-family:inherit;">
                </div>
                
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="closeTerminationModal()" style="flex:1; padding:12px; border:none; border-radius:8px; background:#ddd; color:#444; font-weight:600; cursor:pointer;">Cancel</button>
                    <button type="submit" onclick="submitEarlyTermination(event)" style="flex:1; padding:12px; border:none; border-radius:8px; background:#dc3545; color:white; font-weight:600; cursor:pointer;">End Trip</button>
                </div>
            </form>
        </div>
    </div>

    <script async src="https://maps.googleapis.com/maps/api/js?key=<?php echo MAPS_API_KEY; ?>&callback=initMap">
    </script>

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
            console.log("Map initialized!");
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

            // 1. Always start with the origin
            optimizedStops.push(rawStops[0]);

            // 2. Add intermediate waypoints IN THE OPTIMIZED ORDER
            let intermediate = rawStops.slice(1, rawStops.length - 1);
            order.forEach(index => {
                optimizedStops.push(intermediate[index]);
            });

            // 3. Always end with the destination
            optimizedStops.push(rawStops[rawStops.length - 1]);
            
            // Scope for tracking
            window.renderedStops = optimizedStops;

            // 4. Render the UI
            const listDiv = document.getElementById('stopsList');
            listDiv.innerHTML = '';

            optimizedStops.forEach((stop, index) => {
                let label = "En Route Stop";
                let dotColor = "var(--primary-blue)";

                if (index === 0) {
                    label = "Start Point";
                } else if (index === optimizedStops.length - 1) {
                    label = "Final Destination";
                    dotColor = "var(--accent-yellow)";
                }

                listDiv.innerHTML += `
                <div class="stop-item" id="stopDisplay-${index}">
                    <div class="stop-dot" style="border-color:${dotColor}"></div>
                    <div style="font-weight:600; font-size:0.95rem; color:#333;">${stop.name}</div>
                    <div style="font-size:0.8rem; color:#888;">
                        <i class="fas ${index === 0 ? 'fa-flag' : (index === optimizedStops.length - 1 ? 'fa-map-marker-alt' : 'fa-map-pin')}" style="margin-right:4px;"></i>
                        ${label}
                    </div>
                </div>`;
            });
            
            // Check immediately just in case
            if (currentLocation) checkGeofenceProgress(currentLocation.lat, currentLocation.lng);
        }

        function generateDeepLink(origin, dest, waypoints, order) {
            let baseUrl = "https://www.google.com/maps/dir/?api=1";
            const getLocString = (loc) => (typeof loc === 'string') ? encodeURIComponent(loc) : loc.lat() + "," + loc.lng();
            let url = `${baseUrl}&origin=${getLocString(origin)}&destination=${getLocString(dest)}`;
            if (order.length > 0) {
                let wpStrings = [];
                order.forEach(index => {
                    let loc = waypoints[index].location;
                    if (loc.query) wpStrings.push(encodeURIComponent(loc.query));
                    else wpStrings.push(loc.lat() + "," + loc.lng());
                });
                url += `&waypoints=${wpStrings.join('|')}&travelmode=driving`;
            }
            document.getElementById('googleMapsLink').href = url;
        }

        function haversineDist(lat1, lon1, lat2, lon2) {
            const R = 6371e3; // metres
            const p1 = lat1 * Math.PI/180;
            const p2 = lat2 * Math.PI/180;
            const dp = (lat2-lat1) * Math.PI/180;
            const dl = (lon2-lon1) * Math.PI/180;

            const a = Math.sin(dp/2) * Math.sin(dp/2) +
                      Math.cos(p1) * Math.cos(p2) *
                      Math.sin(dl/2) * Math.sin(dl/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c; 
        }

        let currentLocation = null;

        function trackLocation() {
            if ("geolocation" in navigator) {
                navigator.geolocation.watchPosition((position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    currentLocation = { lat: lat, lng: lng };
                    
                    const scheduleId = "<?= $scheduleId ?>";
                    const formData = new FormData();
                    formData.append('schedule_id', scheduleId);
                    formData.append('lat', lat);
                    formData.append('lng', lng);
                    if (navigator.sendBeacon) navigator.sendBeacon('update_location.php', formData);
                    else fetch('update_location.php', { method: 'POST', body: formData });
                    
                    checkGeofenceProgress(lat, lng);
                }, null, { enableHighAccuracy: true, maximumAge: 0, timeout: 5000 });
            }
        }
        
        function checkGeofenceProgress(driverLat, driverLng) {
            if (!window.renderedStops) return;
            const GEOFENCE_RADIUS_M = 50; 
            
            window.renderedStops.forEach((sData, index) => {
                if (!sData.lat || !sData.lng) return;
                
                const dist = haversineDist(driverLat, driverLng, parseFloat(sData.lat), parseFloat(sData.lng));
                if (dist <= GEOFENCE_RADIUS_M) {
                    const el = document.getElementById('stopDisplay-' + index);
                    if (el) {
                        const dotDiv = el.querySelector('.stop-dot');
                        if (dotDiv) {
                            dotDiv.className = 'fas fa-check-circle stop-dot-visited';
                            dotDiv.style.border = 'none';
                            dotDiv.style.color = 'var(--success)';
                            dotDiv.style.fontSize = '1.4rem';
                            dotDiv.style.background = 'transparent';
                            dotDiv.style.top = '0px';
                            dotDiv.style.left = '-34px';
                        }
                    }
                }
            });
        }
        
        function triggerEndTrip() {
            if (!window.renderedStops) {
                document.getElementById('terminationModal').style.display = 'flex';
                return;
            }
            
            const dest = window.renderedStops[window.renderedStops.length - 1]; 
            
            if (currentLocation && dest && dest.lat && dest.lng) {
                const dist = haversineDist(currentLocation.lat, currentLocation.lng, parseFloat(dest.lat), parseFloat(dest.lng));
                // Geofence lock of 50m
                if (dist <= 50) {
                    if (confirm('Are you sure? This will officially finalize the trip and sync the tracking database.')) {
                        document.getElementById('standardEndForm').submit();
                    }
                } else {
                    document.getElementById('terminationModal').style.display = 'flex';
                }
            } else {
                document.getElementById('terminationModal').style.display = 'flex';
            }
        }

        function toggleOtherReason() {
            const select = document.getElementById('reasonSelect');
            const otherDiv = document.getElementById('otherReasonContainer');
            const otherInput = document.getElementById('otherReasonInput');
            
            if (select.value === 'Other') {
                otherDiv.style.display = 'block';
                otherInput.required = true;
            } else {
                otherDiv.style.display = 'none';
                otherInput.required = false;
            }
        }
        
        function closeTerminationModal() {
            document.getElementById('terminationModal').style.display = 'none';
        }
        
        function submitEarlyTermination(e) {
            e.preventDefault();
            const form = document.getElementById('terminationForm');
            if (form.checkValidity()) {
                const select = document.getElementById('reasonSelect').value;
                if (select === 'Other') {
                    // Injecting input value for PHP to securely capture
                    const typed = document.getElementById('otherReasonInput').value;
                    const opt = document.createElement("option");
                    opt.value = typed;
                    opt.selected = true;
                    document.getElementById('reasonSelect').appendChild(opt);
                }
                form.submit();
            } else {
                form.reportValidity();
            }
        }

        function startScanner() {
            document.getElementById('scanModal').style.display = 'flex';
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess, (e) => { });
        }

        // --- FIX: Scanner Logic Update ---

        function stopScanner() {
            if (html5QrcodeScanner) {
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
                    if (data.success) {
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