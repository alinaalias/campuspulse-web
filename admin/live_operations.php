<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$zones = [];
$zonesSnapshot = $firestore->collection('Zones')->documents();
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
$stopsSnapshot = $firestore->collection('Stops')->documents();
foreach ($stopsSnapshot as $s) {
    $sData = $s->data();
    $sData['id'] = $s->id();
    $sData['lat'] = isset($sData['lat']) ? (float) $sData['lat'] : 0;
    $sData['lng'] = isset($sData['lng']) ? (float) $sData['lng'] : 0;
    $stops[] = $sData;
}

$shuttleToDriver = [];
try {
    $drivers = $firestore->collection('Staffs')
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
$depth = '../';
include $depth . 'layout/admin/header.php';
?>

<style>
    /* Flex Layout for Map + Sidebar */
    .ops-layout {
        display: flex;
        gap: 20px;
        height: calc(100vh - 150px);
        margin-top: 10px;
        align-items: stretch;
        position: relative;
        /* Required for absolute floating button */
        overflow: hidden;
        /* Hide the sidebar cleanly when collapsed */
        padding-right: 5px;
        /* Slight buffer for box-shadows */
    }

    .map-container {
        flex: 1;
        position: relative;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        border: 1px solid #e0e0e0;
    }

    #liveMap {
        width: 100%;
        height: 100%;
        background: #eaebed;
    }

    /* MODIFIED: Sidebar Wrapper for Smooth Collapse Animation */
    .sidebar-wrapper {
        width: 280px;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        flex-shrink: 0;
        overflow: hidden;
    }

    .sidebar-wrapper.collapsed {
        width: 0;
        opacity: 0;
        margin-left: -20px;
        /* Eats the grid gap so map stretches to 100% */
    }

    .activity-sidebar {
        width: 280px;
        /* Keep exact width so text doesn't warp during animation */
        height: 100%;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
        border: 1px solid #e0e0e0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    /* NEW: Floating Tab Button */
    .log-floating-btn {
        position: absolute;
        right: 290px;
        /* 280px sidebar + 10px (half gap) */
        top: 50%;
        transform: translateY(-50%) translateX(50%);
        width: 36px;
        height: 36px;
        background: white;
        border: 1px solid #cbd5e1;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 1000;
        color: #475569;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .log-floating-btn:hover {
        color: var(--primary-blue);
        background: #f8f9fa;
        border-color: var(--primary-blue);
    }

    .log-floating-btn.collapsed {
        right: 0;
        transform: translateY(-50%) translateX(-20px);
        /* Peek smoothly inside map border */
    }

    .log-header {
        padding: 18px 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
        font-weight: 700;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.05rem;
    }

    #liveActivityLog {
        flex: 1;
        overflow-y: auto;
        padding: 20px 15px;
        background: #fafbfc;
    }

    .log-item {
        margin-bottom: 18px;
        padding-left: 12px;
        border-left: 3px solid #cbd5e1;
        animation: fadeIn 0.4s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateX(10px);
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .log-time {
        font-size: 0.75rem;
        color: #64748b;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .log-msg {
        font-size: 0.85rem;
        color: #334155;
        font-weight: 600;
        line-height: 1.4;
    }

    .layer-controls {
        position: absolute;
        top: 8px;
        right: 56px;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.98);
        padding: 15px 20px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        min-width: 230px;
        border: 1px solid #eee;
    }

    .layer-controls-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        padding-bottom: 8px;
    }

    .layer-controls-header h4 {
        margin: 0;
        font-size: 0.95rem;
        color: #2c3e50;
    }

    .layer-controls-content {
        margin-top: 10px;
        border-top: 1px solid #eee;
        padding-top: 15px;
        display: block;
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

    .search-select {
        width: 100%;
        padding: 8px 10px;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        font-family: 'Poppins', sans-serif;
        font-size: 0.85rem;
        color: #334155;
        margin-bottom: 15px;
        outline: none;
        transition: border-color 0.2s;
    }

    .search-select:focus {
        border-color: #3498db;
    }
</style>

<h2 class="page-title"><i class="fas fa-tower-broadcast"></i> Live Operations</h2>

<div class="ops-layout">

    <div class="map-container">
        <div class="layer-controls">
            <div class="layer-controls-header" onclick="toggleLayerControls()">
                <h4><i class="fas fa-layer-group"></i> Layer Controls</h4>
                <i class="fas fa-chevron-up" id="layerToggleIcon" style="color: #94a3b8;"></i>
            </div>

            <div id="layerControlsContent" class="layer-controls-content">
                <div>
                    <label
                        style="font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 4px; display: block;">Find
                        Shuttle</label>
                    <select id="shuttleSearch" class="search-select" onchange="focusShuttle(this.value)">
                        <option value="">-- Select Shuttle --</option>
                    </select>
                </div>

                <div class="toggle-row">Show System Log <label class="switch"><input type="checkbox" id="toggleLogCb"
                            checked onchange="toggleSystemLog()"><span class="slider"></span></label></div>

                <div class="toggle-row">Show All Stops <label class="switch"><input type="checkbox" id="toggleStops"
                            checked onchange="updateLayers()"><span class="slider"></span></label></div>
                <div class="toggle-row">Show Heatmaps <label class="switch"><input type="checkbox" id="toggleZones"
                            checked onchange="updateLayers()"><span class="slider"></span></label></div>
                <div class="toggle-row">Active Shuttles Only <label class="switch"><input type="checkbox"
                            id="toggleActiveOnly" onchange="updateLayers()"><span class="slider"></span></label></div>
            </div>
        </div>
        <div id="liveMap"></div>
    </div>

    <button class="log-floating-btn" id="logFloatingBtn" onclick="toggleSystemLog()" title="Toggle System Log">
        <i class="fas fa-chevron-right" id="logFloatingIcon"></i>
    </button>

    <div class="sidebar-wrapper" id="sidebarWrapper">
        <div class="activity-sidebar">
            <div class="log-header">
                <i class="fas fa-bolt" style="color: #f39c12;"></i> Live System Log
            </div>
            <div id="liveActivityLog">
                <div style="text-align:center; color:#94a3b8; font-size:0.9rem; margin-top:20px;">
                    <i class="fas fa-circle-notch fa-spin"></i> Establishing connection...
                </div>
            </div>
        </div>
    </div>

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

    // NEW: Toggles the entire System Log Sidebar smoothly
    function toggleSystemLog() {
        const wrapper = document.getElementById('sidebarWrapper');
        const btn = document.getElementById('logFloatingBtn');
        const icon = document.getElementById('logFloatingIcon');
        const cb = document.getElementById('toggleLogCb');

        wrapper.classList.toggle('collapsed');
        btn.classList.toggle('collapsed');

        const isCollapsed = wrapper.classList.contains('collapsed');
        cb.checked = !isCollapsed; // Sync with Layer Controls checkbox

        if (isCollapsed) {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-left');
        } else {
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
        }
    }

    // Toggles the Layer Controls Panel
    function toggleLayerControls() {
        const content = document.getElementById('layerControlsContent');
        const icon = document.getElementById('layerToggleIcon');

        if (content.style.display === 'none') {
            content.style.display = 'block';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        } else {
            content.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    }

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

        // Live Shuttles Listener
        db.collection('Shuttles').onSnapshot(snapshot => {
            const searchSelect = document.getElementById('shuttleSearch');

            snapshot.forEach(doc => {
                const data = doc.data();
                const id = doc.id;

                if (!data.current_lat || !data.current_lng) return;

                const isOnline = data.is_online === true;
                const pos = { lat: parseFloat(data.current_lat), lng: parseFloat(data.current_lng) };
                const driverName = shuttleToDriverMap[id] || 'No Driver Assigned';
                const jobStatus = data.job_status || 'idle';
                const jobBadgeColor = (jobStatus === 'in job' || jobStatus === 'on trip') ? '#e67e22' : '#27ae60';

                if (!Array.from(searchSelect.options).some(opt => opt.value === id)) {
                    const opt = document.createElement('option');
                    opt.value = id;
                    const plate = data.plate_number ? `(${data.plate_number})` : '';
                    opt.text = `${id} ${plate}`;
                    searchSelect.appendChild(opt);
                }

                if (!fleetMarkers[id]) {
                    const imgDiv = document.createElement('img');
                    imgDiv.src = "../img/van.png";
                    imgDiv.style.width = "40px";
                    imgDiv.style.height = "40px";
                    imgDiv.style.opacity = isOnline ? '1.0' : '0.5';
                    imgDiv.style.cursor = 'pointer';

                    fleetMarkers[id] = new google.maps.marker.AdvancedMarkerElement({
                        position: pos,
                        map: map,
                        content: imgDiv,
                        title: `Shuttle ${id}`,
                        zIndex: isOnline ? 100 : 50
                    });

                    fleetMarkers[id].isOnline = isOnline;

                    imgDiv.addEventListener('click', () => {

                        activeInfoWindowShuttleId = id;
                        sessionStorage.setItem('activeInfoWindowShuttleId', id);

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

                // ─────────────────────────────────────────────
                // FIX: Auto reopen InfoWindow after refresh
                // ─────────────────────────────────────────────
                const savedId = sessionStorage.getItem('activeInfoWindowShuttleId');

                if (savedId === id && fleetMarkers[id]) {
                    setTimeout(() => {
                        // simulate click ONLY after marker is ready
                        const markerContent = fleetMarkers[id].content;

                        if (markerContent) {
                            markerContent.dispatchEvent(
                                new MouseEvent('click', {
                                    bubbles: true,
                                    cancelable: true
                                })
                            );
                        }
                    }, 800);
                }
            });
        });

        // Live Activity Log Listener
        db.collection('Announcements')
            .orderBy('created_at', 'desc')
            .limit(40)
            .onSnapshot(snapshot => {
                const logContainer = document.getElementById('liveActivityLog');
                logContainer.innerHTML = '';

                let visibleCount = 0;

                snapshot.forEach(doc => {
                    const data = doc.data();

                    const isEmergency = data.tag && data.tag.includes('Emergency');
                    const isWarning = data.tag && data.tag.includes('Warning');
                    const isDriverReport = data.location_lat && data.location_lat !== 'N/A';
                    const isResolved = data.status === 'resolved';

                    if (!isEmergency && !isWarning && !isDriverReport && !isResolved) {
                        return; // Skip standard info broadcasts
                    }

                    visibleCount++;

                    let timeStr = '';
                    if (data.created_at) {
                        const d = new Date(data.created_at.replace(' ', 'T'));
                        const datePart = d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
                        const timePart = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        timeStr = `${datePart} at ${timePart}`;
                    }

                    let statusColor = '#3498db';
                    let icon = 'fa-info-circle';
                    let simplifiedMsg = '';
                    let shuttleIdRef = data.shuttle_id ? data.shuttle_id : 'A shuttle';

                    // NEW: Smart Wording & Iconography Engine
                    let titleStr = data.title || 'An issue';
                    let titleLower = titleStr.toLowerCase();
                    let locText = (data.location_name && data.location_name !== 'N/A') ? ` near <b>${data.location_name}</b>` : '';

                    if (isResolved) {
                        statusColor = '#27ae60';
                        icon = 'fa-check-circle';
                        let replacementId = data.resolution_notes ? data.resolution_notes.replace('Replaced with ', '').trim() : 'a replacement';
                        simplifiedMsg = `Shuttle <strong>${shuttleIdRef}</strong> was successfully replaced by <strong>${replacementId}</strong>.`;

                    } else if (isEmergency) {
                        statusColor = '#e74c3c';
                        icon = (titleLower.includes('accident') || titleLower.includes('crash')) ? 'fa-car-crash' : (titleLower.includes('breakdown') ? 'fa-tools' : 'fa-exclamation-triangle');
                        let cleanTitle = titleStr.replace(/reported/ig, '').trim() || 'Emergency';
                        simplifiedMsg = `<b>${cleanTitle}</b> reported by <strong>${shuttleIdRef}</strong>${locText}.`;

                    } else if (isWarning) {
                        statusColor = '#f39c12';
                        icon = titleLower.includes('traffic') ? 'fa-traffic-light' : ((titleLower.includes('rain') || titleLower.includes('weather')) ? 'fa-cloud-showers-heavy' : 'fa-exclamation-circle');
                        let cleanTitle = titleStr.replace(/reported/ig, '').trim() || 'Warning';
                        simplifiedMsg = `<b>${cleanTitle}</b> reported by <strong>${shuttleIdRef}</strong>${locText}.`;

                    } else {
                        simplifiedMsg = `Live update sent by <strong>${shuttleIdRef}</strong>${locText}.`;
                    }

                    logContainer.innerHTML += `
                        <div class="log-item" style="border-left-color: ${statusColor};">
                            <div class="log-time">
                                <i class="fas ${icon}" style="color:${statusColor}"></i> ${timeStr}
                            </div>
                            <div class="log-msg">${simplifiedMsg}</div>
                        </div>
                    `;
                });

                if (visibleCount === 0) {
                    logContainer.innerHTML = '<div style="text-align:center; color:#94a3b8; font-size:0.9rem; margin-top:20px;">No crucial activity reported</div>';
                }
            });
    }

    function focusShuttle(id) {
        if (!id || !fleetMarkers[id]) return;
        const marker = fleetMarkers[id];
        map.setCenter(marker.position);
        map.setZoom(18);
        marker.content.click();
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

    let isInteracting = false;
    let activeInfoWindowShuttleId = null;

    function savePageState() {
        const state = {

            selectedShuttle: document.getElementById('shuttleSearch')?.value || '',

            activeInfoWindowShuttleId: activeInfoWindowShuttleId,


            toggleStops: document.getElementById('toggleStops')?.checked || false,
            toggleZones: document.getElementById('toggleZones')?.checked || false,
            toggleActiveOnly: document.getElementById('toggleActiveOnly')?.checked || false,
            toggleLogCb: document.getElementById('toggleLogCb')?.checked || false,

            sidebarCollapsed: document.getElementById('sidebarWrapper')?.classList.contains('collapsed') || false,

            mapCenter: map ? {
                lat: map.getCenter().lat(),
                lng: map.getCenter().lng()
            } : null,

            mapZoom: map ? map.getZoom() : 15
        };

        sessionStorage.setItem('liveOpsPageState', JSON.stringify(state));
    }

    function restorePageState() {
        const raw = sessionStorage.getItem('liveOpsPageState');

        if (!raw) return;

        try {
            const state = JSON.parse(raw);

            // Restore toggles
            if (document.getElementById('toggleStops')) {
                document.getElementById('toggleStops').checked = state.toggleStops;
            }

            if (document.getElementById('toggleZones')) {
                document.getElementById('toggleZones').checked = state.toggleZones;
            }

            if (document.getElementById('toggleActiveOnly')) {
                document.getElementById('toggleActiveOnly').checked = state.toggleActiveOnly;
            }

            if (document.getElementById('toggleLogCb')) {
                document.getElementById('toggleLogCb').checked = state.toggleLogCb;
            }

            // Restore sidebar state
            const wrapper = document.getElementById('sidebarWrapper');
            const btn = document.getElementById('logFloatingBtn');
            const icon = document.getElementById('logFloatingIcon');

            if (state.sidebarCollapsed && wrapper && btn && icon) {
                wrapper.classList.add('collapsed');
                btn.classList.add('collapsed');

                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            }

            // Restore map position
            if (map && state.mapCenter) {
                map.setCenter(state.mapCenter);
                map.setZoom(state.mapZoom || 15);
            }

            // Restore selected shuttle
            setTimeout(() => {
                const shuttleSelect = document.getElementById('shuttleSearch');

                if (shuttleSelect && state.selectedShuttle) {
                    shuttleSelect.value = state.selectedShuttle;
                }

                if (
                    state.activeInfoWindowShuttleId &&
                    fleetMarkers[state.activeInfoWindowShuttleId]
                ) {
                    google.maps.event.trigger(
                        fleetMarkers[state.activeInfoWindowShuttleId].content,
                        'click'
                    );
                }

                updateLayers();
            }, 1000);

        } catch (e) {
            console.error('Failed restoring page state:', e);
        }
    }

    // Detect user interaction
    document.addEventListener('DOMContentLoaded', () => {

        const interactionSelectors = `
            input,
            select,
            button,
            textarea,
            .interaction-target,
            .layer-controls,
            .activity-sidebar
        `;

        document.querySelectorAll(interactionSelectors).forEach(el => {

            el.addEventListener('focus', () => isInteracting = true);
            el.addEventListener('blur', () => isInteracting = false);

            el.addEventListener('mouseenter', () => isInteracting = true);
            el.addEventListener('mouseleave', () => isInteracting = false);

            el.addEventListener('change', () => {
                isInteracting = true;
                savePageState();

                setTimeout(() => {
                    restorePageState();

                    const savedId = sessionStorage.getItem('activeInfoWindowShuttleId');

                    if (savedId && fleetMarkers[savedId]) {
                        setTimeout(() => {
                            google.maps.event.trigger(fleetMarkers[savedId].content, 'click');
                        }, 800);
                    }
                }, 2500);
            });
        });

        // Restore saved state after map initializes
        setTimeout(() => {
            restorePageState();
        }, 1500);
    });

    // Save state before leaving/reloading
    window.addEventListener('beforeunload', savePageState);

    // Auto refresh every 15 seconds
    setInterval(() => {

        if (!isInteracting) {
            savePageState();
            window.location.reload();
        }

    }, 15000);
</script>
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=<?= MAPS_API_KEY ?>&libraries=marker&callback=initMap&loading=async"></script>

<?php include $depth . 'layout/admin/footer.php'; ?>