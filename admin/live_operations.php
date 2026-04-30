<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$zones = [];
$zonesSnapshot = $firestore->database()->collection('Zones')->documents();
foreach ($zonesSnapshot as $z) {
    $zData = $z->data();
    $zData['id'] = $z->id();
    if (isset($zData['center_point'])) {
        $zData['lat'] = (float) $zData['center_point']->latitude();
        $zData['lng'] = (float) $zData['center_point']->longitude();
    }
    $zones[$z->id()] = $zData;
}

$stops = [];
$stopsSnapshot = $firestore->database()->collection('Stops')->documents();
foreach ($stopsSnapshot as $s) {
    $sData = $s->data();
    $sData['id'] = $s->id();
    $sData['lat'] = isset($sData['lat']) ? (float) $sData['lat'] : 0;
    $sData['lng'] = isset($sData['lng']) ? (float) $sData['lng'] : 0;
    $stops[] = $sData;
}

$shuttleToDriver = [];
try {
    $drivers = $firestore->database()->collection('Staffs')
        ->where('role', '==', 'driver')
        ->documents();

    foreach ($drivers as $d) {
        $staffData = $d->data();
        $assignedShuttle = $staffData['assigned_shuttle_id'] ?? null;
        if ($assignedShuttle) {
            $shuttleToDriver[$assignedShuttle] = $staffData['full_name'] ?? 'Unknown Driver';
        }
    }
} catch (Exception $e) {
}

$pageTitle = 'Live Operations - CampusPulse';
include '../layout/admin_header.php';
?>

<style>
    .map-container {
        position: relative;
        width: 100%;
        height: calc(100vh - 150px);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        border: 1px solid #e0e0e0;
        margin-top: 10px;
    }

    #liveMap {
        width: 100%;
        height: 100%;
        background: #eaebed;
    }

    .layer-controls {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.98);
        padding: 15px 20px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        min-width: 230px;
        border: 1px solid #eee;
    }

    .layer-controls h4 {
        margin: 0 0 15px 0;
        font-size: 0.95rem;
        color: #2c3e50;
        border-bottom: 1px solid #eee;
        padding-bottom: 8px;
    }

    .toggle-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #555;
    }

    .switch {
        position: relative;
        display: inline-block;
        width: 36px;
        height: 20px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 20px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 14px;
        width: 14px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: #2ecc71;
    }

    input:checked+.slider:before {
        transform: translateX(16px);
    }

    .infowindow-content {
        padding: 10px;
        font-family: 'Poppins', sans-serif;
        min-width: 220px;
    }

    .iw-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        border-bottom: 1px solid #eee;
        padding-bottom: 8px;
    }

    .iw-title {
        margin: 0;
        font-size: 1rem;
        color: #2c3e50;
        font-weight: 700;
    }

    .job-badge {
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.65rem;
        color: #fff;
        font-weight: 800;
        text-transform: uppercase;
    }

    .status-pill {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        color: #fff;
        font-weight: 600;
        margin-bottom: 10px;
    }

    .iw-details {
        font-size: 0.85rem;
        color: #555;
        line-height: 1.6;
    }

    .iw-details i {
        width: 20px;
        color: #3498db;
    }
</style>

<h2 class="page-title"><i class="fas fa-tower-broadcast"></i> Live Operations</h2>
<div class="map-container">
    <div class="layer-controls">
        <h4><i class="fas fa-layer-group"></i> Layer Controls</h4>
        <div class="toggle-row">Show All Stops <label class="switch"><input type="checkbox" id="toggleStops" checked
                    onchange="updateLayers()"><span class="slider"></span></label></div>
        <div class="toggle-row">Show Heatmaps <label class="switch"><input type="checkbox" id="toggleZones" checked
                    onchange="updateLayers()"><span class="slider"></span></label></div>
        <div class="toggle-row">Active Shuttles Only <label class="switch"><input type="checkbox" id="toggleActiveOnly"
                    onchange="updateLayers()"><span class="slider"></span></label>
        </div>
    </div>
    <div id="liveMap"></div>
</div>


<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>
<script>
    const zonesData = <?= json_encode($zones) ?: '[]' ?>;
    const stopsData = <?= json_encode($stops) ?: '[]' ?>;
    const shuttleToDriverMap = <?= json_encode($shuttleToDriver) ?: '[]' ?>;

    let map, infoWindow;
    const zoneShapes = [], zoneLabels = [], stopMarkers = [], fleetMarkers = {};

    const firebaseConfig = {
        apiKey: "<?= MAPS_API_KEY ?>",
        authDomain: "<?= FIREBASE_AUTH_DOMAIN ?>",
        projectId: "<?= FIREBASE_PROJECT_ID ?>",
        storageBucket: "<?= FIREBASE_STORAGE_BUCKET ?>",
        messagingSenderId: "<?= FIREBASE_MESSAGING_SENDER_ID ?>",
        appId: "<?= FIREBASE_APP_ID ?>"
    };

    function getConvexHull(points) {
        if (points.length < 3) return points;
        let sorted = points.slice().sort((a, b) => a.lng === b.lng ? a.lat - b.lat : a.lng - b.lng);
        const cross = (o, a, b) => (a.lng - o.lng) * (b.lat - o.lat) - (a.lat - o.lat) * (b.lng - o.lng);
        let lower = [];
        for (let p of sorted) {
            while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop();
            lower.push(p);
        }
        let upper = [];
        for (let i = sorted.length - 1; i >= 0; i--) {
            let p = sorted[i];
            while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop();
            upper.push(p);
        }
        upper.pop(); lower.pop();
        return lower.concat(upper);
    }

    function inflatePolygon(points, scaleFactor) {
        if (points.length === 0) return points;
        let cLat = 0, cLng = 0;
        points.forEach(p => { cLat += p.lat; cLng += p.lng; });
        cLat /= points.length; cLng /= points.length;
        return points.map(p => ({
            lat: cLat + (p.lat - cLat) * scaleFactor,
            lng: cLng + (p.lng - cLng) * scaleFactor
        }));
    }

    function getZoneColor(id) {
        const palette = ['#3498db', '#e74c3c', '#2ecc71', '#9b59b6', '#f39c12', '#1abc9c', '#e67e22'];
        const keys = Object.keys(zonesData);
        const idx = keys.indexOf(id);
        return palette[idx % palette.length] || '#34495e';
    }

    function initMap() {
        map = new google.maps.Map(document.getElementById("liveMap"), {
            center: { lat: 3.1592, lng: 101.7036 },
            zoom: 15,
            mapId: "DEMO_MAP_ID",
            mapTypeControl: true,
            mapTypeControlOptions: {
                style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                position: google.maps.ControlPosition.TOP_LEFT
            },
            streetViewControl: false
        });
        infoWindow = new google.maps.InfoWindow();

        const zonePoints = {};
        stopsData.forEach(stop => {
            if (stop.lat && stop.lng && stop.zone_ids) {
                stop.zone_ids.forEach(zid => {
                    if (!zonePoints[zid]) zonePoints[zid] = [];
                    zonePoints[zid].push({ lat: stop.lat, lng: stop.lng });
                });
            }
        });

        Object.keys(zonePoints).forEach(zid => {
            const points = zonePoints[zid];
            if (points.length >= 3) {
                const hull = getConvexHull(points);
                const paddedHull = inflatePolygon(hull, 1.25);
                const color = getZoneColor(zid);

                const poly = new google.maps.Polygon({
                    paths: paddedHull,
                    strokeColor: color,
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: color,
                    fillOpacity: 0.15,
                    map: map
                });
                zoneShapes.push(poly);

                let cLat = 0, cLng = 0;
                paddedHull.forEach(p => { cLat += p.lat; cLng += p.lng; });
                const centroid = { lat: cLat / paddedHull.length, lng: cLng / paddedHull.length };

                const labelDiv = document.createElement('div');
                labelDiv.textContent = zonesData[zid].name || zid;
                labelDiv.style.color = color;
                labelDiv.style.fontWeight = 'bold';
                labelDiv.style.fontSize = '12px';
                labelDiv.style.textShadow = '1px 1px 2px white, -1px -1px 2px white, 1px -1px 2px white, -1px 1px 2px white';

                const label = new google.maps.marker.AdvancedMarkerElement({
                    position: centroid,
                    map: map,
                    content: labelDiv
                });
                zoneLabels.push(label);
            }
        });

        stopsData.forEach(s => {
            const color = (s.zone_ids && s.zone_ids.length > 0) ? getZoneColor(s.zone_ids[0]) : '#444';

            const stopDiv = document.createElement('div');
            stopDiv.style.width = '12px';
            stopDiv.style.height = '12px';
            stopDiv.style.backgroundColor = color;
            stopDiv.style.border = '1.5px solid #ffffff';
            stopDiv.style.borderRadius = '50%';
            stopDiv.style.boxShadow = '0 1px 3px rgba(0,0,0,0.3)';

            const marker = new google.maps.marker.AdvancedMarkerElement({
                position: { lat: s.lat, lng: s.lng },
                map: map,
                content: stopDiv
            });
            stopMarkers.push(marker);
        });

        if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();

        db.collection('Shuttles').onSnapshot(snapshot => {
            snapshot.forEach(doc => {
                const data = doc.data();
                const id = doc.id;
                if (!data.current_lat || !data.current_lng) return;

                const isOnline = data.is_online === true;
                const pos = { lat: parseFloat(data.current_lat), lng: parseFloat(data.current_lng) };
                const driverName = shuttleToDriverMap[id] || 'No Driver Assigned';
                const jobStatus = data.job_status || 'Idle';
                const jobBadgeColor = (jobStatus === 'In Job' || jobStatus === 'On Trip') ? '#e67e22' : '#27ae60';

                if (!fleetMarkers[id]) {

                    const imgDiv = document.createElement('img');
                    imgDiv.src = "../img/van.png";
                    imgDiv.style.width = "40px";
                    imgDiv.style.height = "40px";
                    imgDiv.style.opacity = isOnline ? '1.0' : '0.5';
                    imgDiv.style.cursor = 'pointer'; // Make it look clickable

                    fleetMarkers[id] = new google.maps.marker.AdvancedMarkerElement({
                        position: pos,
                        map: map,
                        content: imgDiv,
                        title: `Shuttle ${id}`, // Required to register as a clickable layer
                        zIndex: isOnline ? 100 : 50
                    });

                    fleetMarkers[id].isOnline = isOnline;

                    // FIX: Attach the click listener directly to the HTML Image Element
                    imgDiv.addEventListener('click', () => {
                        const zName = zonesData[data.zone_id] ? zonesData[data.zone_id].name : 'Unassigned';
                        infoWindow.setContent(`
                                <div class="infowindow-content">
                                    <div class="iw-header">
                                        <h4 class="iw-title">Shuttle ${id}</h4>
                                        <span class="job-badge" style="background:${jobBadgeColor}">${jobStatus}</span>
                                    </div>
                                    <span class="status-pill" style="background:${isOnline ? '#2ecc71' : '#95a5a6'}">
                                        ${isOnline ? 'NETWORK: ONLINE' : 'NETWORK: OFFLINE'}
                                    </span>
                                    <div class="iw-details">
                                        <div><i class="fas fa-user-tie"></i> <b>Driver:</b> ${driverName}</div>
                                        <div><i class="fas fa-map-marked-alt"></i> <b>Zone:</b> ${zName}</div>
                                    </div>
                                </div>
                            `);
                        infoWindow.open(map, fleetMarkers[id]);
                    });
                } else {
                    fleetMarkers[id].position = pos;
                    fleetMarkers[id].content.style.opacity = isOnline ? '1.0' : '0.5';
                    fleetMarkers[id].zIndex = isOnline ? 100 : 50;
                    fleetMarkers[id].isOnline = isOnline;
                }

                const activeOnly = document.getElementById('toggleActiveOnly').checked;
                fleetMarkers[id].map = (activeOnly && !isOnline) ? null : map;
            });
        });
    }

    function updateLayers() {
        const showStops = document.getElementById('toggleStops').checked;
        const showZones = document.getElementById('toggleZones').checked;
        const activeOnly = document.getElementById('toggleActiveOnly').checked;

        stopMarkers.forEach(m => m.map = showStops ? map : null);
        zoneShapes.forEach(c => c.setMap(showZones ? map : null));
        zoneLabels.forEach(l => l.map = showZones ? map : null);

        for (let id in fleetMarkers) {
            const isOnline = fleetMarkers[id].isOnline;
            fleetMarkers[id].map = (activeOnly && !isOnline) ? null : map;
        }
    }
</script>
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=<?= MAPS_API_KEY ?>&libraries=marker&callback=initMap&loading=async"></script>

<?php include '../layout/admin_footer.php'; ?>