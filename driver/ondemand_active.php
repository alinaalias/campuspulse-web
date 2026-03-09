<?php
session_start();
require_once '../config.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 2. FETCH ACTIVE POOLED JOBS
// We find all 'ondemand' bookings assigned to this driver that are active.
$bookingsRef = $firestore->database()->collection('Bookings')
    ->where('driver_id', '=', $driverId)
    ->where('type', '=', 'ondemand')
    ->where('status', 'in', ['confirmed', 'arriving', 'onboard']);

$documents = $bookingsRef->documents();
$waypoints = [];
$missionData = [];

// Helper to Fetch Names (Student & Stop)
foreach ($documents as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $id = $doc->id();
    $status = $data['status'];

    // Resolve Student Name
    $sName = "Student";
    if (!empty($data['user_id'])) {
        $sSnap = $firestore->database()->collection('Students')->document($data['user_id'])->snapshot();
        if ($sSnap->exists()) $sName = $sSnap->data()['full_name'] ?? "Student";
    }

    // LOGIC: Determine Next Action (Pickup vs Dropoff)
    $targetType = '';
    $stopId = '';

    if (in_array($status, ['confirmed', 'arriving'])) {
        $targetType = 'pickup';
        $stopId = $data['pickup_stop_id'];
    } elseif ($status === 'onboard') {
        $targetType = 'dropoff';
        $stopId = $data['dropoff_stop_id'];
    } else {
        continue;
    }

    // Fetch Stop Info (Coordinates)
    $lat = 0; $lng = 0; $address = "Unknown Location";
    
    if ($stopId) {
        $stSnap = $firestore->database()->collection('Stops')->document($stopId)->snapshot();
        if ($stSnap->exists()) {
            $stData = $stSnap->data();
            $lat = $stData['latitude'] ?? 0;
            $lng = $stData['longitude'] ?? 0;
            $address = $stData['name'] ?? $stopId;
        }
    }

    // Add to Lists if valid
    if ($lat != 0 && $lng != 0) {
        // Map Waypoint
        $waypoints[] = [
            'location' => ['lat' => (float)$lat, 'lng' => (float)$lng],
            'stopover' => true
        ];

        // UI Data
        $missionData[] = [
            'booking_id' => $id,
            'type' => $targetType,
            'student' => $sName,
            'address' => $address,
            'lat' => $lat,
            'lng' => $lng,
            'status' => $status
        ];
    }
}

// 3. Encode for JS
$jsMissions = json_encode($missionData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pooled On-Demand</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        #map { width: 100%; height: 45vh; }
        .queue-container { padding: 20px; background: #fff; border-radius: 20px 20px 0 0; margin-top: -20px; position: relative; z-index: 10; min-height: 55vh; box-shadow: 0 -5px 20px rgba(0,0,0,0.1); }
        .stop-card {
            background: #f8f9fa; border-left: 5px solid #ddd;
            padding: 15px; margin-bottom: 15px; border-radius: 8px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .stop-card.pickup { border-left-color: #2ecc71; }
        .stop-card.dropoff { border-left-color: #e74c3c; }
        
        .btn-action {
            padding: 8px 15px; border: none; border-radius: 6px; color: white; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 0.9rem;
        }
        .btn-pickup { background: #2ecc71; box-shadow: 0 4px 10px rgba(46, 204, 113, 0.3); }
        .btn-dropoff { background: #e74c3c; box-shadow: 0 4px 10px rgba(231, 76, 60, 0.3); }
        
        /* Scanner Modal */
        #scanModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; }
        #reader { width: 300px; height: 300px; background: black; border-radius: 12px; overflow: hidden; }
        .close-scan { position: absolute; top: 20px; right: 20px; color: white; font-size: 2rem; cursor: pointer; }
    </style>
</head>
<body style="background:#e9ecef; margin:0;">

    <div class="driver-header" style="position: absolute; top: 0; left: 0; width: 100%; z-index: 100; background: rgba(0,0,0,0.5); padding: 15px;">
        <a href="driver_dashboard.php" style="color:white; text-decoration:none;"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>

    <div id="map"></div>

    <div class="queue-container">
        <h3 style="margin-top:0; color: #333;">Current Route Queue</h3>
        <p style="font-size:0.8rem; color:#888; margin-bottom: 20px;">
            <i class="fas fa-magic" style="color: var(--accent-yellow);"></i> Google Maps is optimizing your path:
        </p>
        
        <div id="route-list">
            <div style="text-align:center; padding:40px; color:#999;">
                <i class="fas fa-circle-notch fa-spin fa-2x"></i><br><br>Calculating Route...
            </div>
        </div>
    </div>

    <div id="scanModal">
        <div class="close-scan" onclick="stopScanner()">×</div>
        <h3 style="color:white; margin-bottom:20px;">Scan Student QR</h3>
        <div id="reader"></div>
    </div>

    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBnjGNcxW0UPWgfG8S7OZP2PEra22BzwDg&libraries=places&callback=initMap" async defer></script>

    <script>
    const allMissions = <?= $jsMissions ?>;
    let map, directionsService, directionsRenderer;
    let driverPos = null;
    let html5QrcodeScanner;
    let currentScanningId = null;

    function initMap() {
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer({
            suppressMarkers: false,
            preserveViewport: false
        });

        // Default Center (KL)
        const kl = { lat: 3.1390, lng: 101.6869 };
        map = new google.maps.Map(document.getElementById("map"), {
            zoom: 14,
            center: kl,
            disableDefaultUI: true
        });

        directionsRenderer.setMap(map);

        // Get Driver Location & Calculate
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(pos => {
                driverPos = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                calculateOptimizedRoute();
            }, () => {
                driverPos = kl; // Fallback
                calculateOptimizedRoute();
            });
        } else {
            driverPos = kl;
            calculateOptimizedRoute();
        }
    }

    function calculateOptimizedRoute() {
        if (allMissions.length === 0) {
            document.getElementById('route-list').innerHTML = 
                "<div style='text-align:center; padding:30px; color:#999;'><i class='fas fa-check-circle fa-3x' style='color:#ddd;'></i><p>All clear! Waiting for new requests.</p></div>";
            return;
        }

        // Prepare Waypoints
        const waypts = allMissions.map(m => ({
            location: { lat: parseFloat(m.lat), lng: parseFloat(m.lng) },
            stopover: true
        }));

        // Handle single stop case
        if (waypts.length === 1) {
            const dest = waypts[0];
            callGoogleAPI([], dest);
            return;
        }

        // Multiple stops: Use the last added one as destination, others as waypoints
        const dest = waypts.pop(); 
        callGoogleAPI(waypts, dest);
    }

    function callGoogleAPI(waypoints, destination) {
        directionsService.route({
            origin: driverPos,
            destination: destination.location,
            waypoints: waypoints,
            optimizeWaypoints: true, // Google TSP Optimization
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            if (status === 'OK') {
                directionsRenderer.setDirections(result);
                // The 'waypoint_order' array tells us the optimized order of INTERMEDIATE stops
                renderOptimizedList(result.routes[0].waypoint_order);
            } else {
                console.error("Route failed: " + status);
                document.getElementById('route-list').innerHTML = "<p style='color:red'>Routing Error. Check API Key.</p>";
            }
        });
    }

    function renderOptimizedList(order) {
        // Reorder missions based on optimization
        let orderedMissions = [];
        
        if (allMissions.length === 1) {
            orderedMissions = allMissions;
        } else {
            const middleMissions = allMissions.slice(0, -1);
            const lastMission = allMissions[allMissions.length - 1];
            
            order.forEach(index => {
                orderedMissions.push(middleMissions[index]);
            });
            orderedMissions.push(lastMission);
        }

        const listDiv = document.getElementById('route-list');
        listDiv.innerHTML = '';

        orderedMissions.forEach((mission, i) => {
            let btnHtml = '';
            
            if (mission.type === 'pickup') {
                btnHtml = `<button class="btn-action btn-pickup" onclick="handlePickup('${mission.booking_id}')">
                             <i class="fas fa-qrcode"></i> Pickup
                           </button>`;
            } else {
                btnHtml = `<button class="btn-action btn-dropoff" onclick="handleDropoff('${mission.booking_id}')">
                             <i class="fas fa-check"></i> Dropoff
                           </button>`;
            }

            const html = `
                <div class="stop-card ${mission.type}">
                    <div>
                        <div style="font-weight:bold; font-size:0.8rem; color:#888; margin-bottom:4px;">
                            STOP ${i+1} • ${mission.type.toUpperCase()}
                        </div>
                        <div style="font-size:1.1rem; color:#333; font-weight:600;">${mission.student}</div>
                        <div style="font-size:0.85rem; color:#555;">
                            <i class="fas fa-map-marker-alt"></i> ${mission.address}
                        </div>
                    </div>
                    ${btnHtml}
                </div>
            `;
            listDiv.innerHTML += html;
        });
    }

    // --- ACTIONS ---
    function handlePickup(bookingId) {
        currentScanningId = bookingId;
        startScanner();
    }

    function handleDropoff(bookingId) {
        if(confirm("Confirm dropoff complete?")) {
             updateStatus(bookingId, 'completed');
        }
    }

    // --- QR SCANNER ---
    function startScanner() {
        document.getElementById('scanModal').style.display = 'flex';
        html5QrcodeScanner = new Html5Qrcode("reader");
        html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess);
    }

    function stopScanner() {
        if(html5QrcodeScanner) {
            html5QrcodeScanner.stop().then(() => {
                document.getElementById('scanModal').style.display = 'none';
            });
        } else {
            document.getElementById('scanModal').style.display = 'none';
        }
    }

    function onScanSuccess(decodedText) {
        // The QR contains the Booking ID. Match it against the one we selected.
        if (decodedText === currentScanningId) {
            html5QrcodeScanner.stop().then(() => {
                document.getElementById('scanModal').style.display = 'none';
                alert("✅ Verified!");
                updateStatus(currentScanningId, 'onboard');
            });
        } else {
            alert("❌ Wrong Ticket! Scanned ID: " + decodedText);
        }
    }

    function updateStatus(bookingId, newStatus) {
        fetch('update_booking_status.php?id=' + bookingId + '&status=' + newStatus)
        .then(() => {
            window.location.reload(); 
        });
    }
    </script>
</body>
</html>