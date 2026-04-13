<?php
session_start();
require_once '../../config.php';

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

/* =========================
   FETCH ZONES (ID → NAME)
========================= */
$zoneMap = [];
$zonesSnapshot = $firestore->database()->collection('Zones')->documents();
foreach ($zonesSnapshot as $z) {
    $zoneMap[$z->id()] = $z->data()['name'] ?? 'Unknown';
}

/* =========================
   FETCH SHUTTLES
========================= */
$shuttles = $firestore->database()->collection('Shuttles')->documents();

/* =========================
   DRIVER REVERSE LOOKUP (Staffs)
========================= */
$assignedDrivers = [];
$staffsSnap = $firestore->database()->collection('Staffs')->where('role', '=', 'driver')->documents();
foreach ($staffsSnap as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    $shuttleId = $d['assigned_shuttle_id'] ?? '';
    if (!empty($shuttleId)) {
        if (!isset($assignedDrivers[$shuttleId])) $assignedDrivers[$shuttleId] = [];
        $assignedDrivers[$shuttleId][] = explode(' ', trim($d['full_name']))[0];
    }
}
$today = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shuttle Management - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        /* Custom Styles for the Collapsible Legend */
.legend-card { background: #f0f4f8; border-left: 4px solid var(--primary-blue); padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; transition: all 0.3s ease; }
.legend-title { margin: 0; font-size: 1rem; color: #2c3e50; font-weight: 700; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none; }
.legend-title-left { display: flex; align-items: center; gap: 8px; }
.legend-grid { display: none; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; font-size: 0.85rem; color: #555; margin-top: 15px; border-top: 1px solid #d1d9e0; padding-top: 15px; }
.legend-grid.show { display: grid; animation: fadeIn 0.3s ease; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 class="page-title">Shuttle Management</h2>
                <a href="add_shuttle.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Shuttle
                </a>
            </div>

            <div class="legend-card" id="quickGuideCard">
    <div class="legend-title" onclick="toggleLegend()">
        <div class="legend-title-left">
            <i class="fas fa-info-circle" style="color:var(--primary-blue);"></i> 
            Quick Guide: Dashboard Legend
        </div>
        <i class="fas fa-chevron-down" id="legendIcon" style="color:#888; transition: transform 0.3s;"></i>
    </div>
    
    <div class="legend-grid" id="legendContent">
        <div class="legend-item">
            <strong><i class="fas fa-wrench"></i> Hardware Health (Status)</strong>
            <div><span class="legend-bullet" style="background:#2ecc71;"></span> <b>Active:</b> Vehicle is safe & operational.</div>
            <div><span class="legend-bullet" style="background:#f39c12;"></span> <b>Maintenance:</b> In workshop. Auto-dispatch disabled.</div>
            <div><span class="legend-bullet" style="background:#e74c3c;"></span> <b>Inactive:</b> Retired/Removed from active fleet.</div>
        </div>
        <div class="legend-item">
            <strong><i class="fas fa-user-tie"></i> Human Presence (Connection)</strong>
            <div><span class="legend-bullet" style="background:var(--success);"></span> <b>Live Online:</b> A driver is currently inside and working.</div>
            <div><span class="legend-bullet" style="background:#999;"></span> <b>Parked / Offline:</b> The driver in a break / Shift is over, van is parked.</div>
        </div>
        <div class="legend-item">
            <strong><i class="fas fa-map-marked-alt"></i> Map Markers</strong>
            <div>Click any dot on the map to see the exact GPS coordinates and get a direct <b>Google Maps Navigation link</b> to the vehicle's location.</div>
        </div>
    </div>
</div>

            <div class="card" style="margin-bottom: 25px; padding: 0; overflow: hidden; border: 1px solid #e0e0e0;">
                <div style="padding: 15px 20px; background: #fafafa; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-boxes" style="color: var(--primary-blue); font-size: 1.2rem;"></i>
                    <h3 style="margin: 0; font-size: 1.1rem; color: #333; font-weight: 600;">Inventory Map</h3>
                </div>
                <div id="fleetRadarMap" style="width: 100%; height: 400px; background: #eaebed; display: flex; justify-content: center; align-items: center;">
                    <span style="color: #777;"><i class="fas fa-spinner fa-spin"></i> Initializing Map...</span>
                </div>
            </div>

            <?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
                <?php 
                    $msg = $_GET['msg'] ?? '';
                    $err = $_GET['err'] ?? '';
                    $alertClass = $err ? 'alert-error' : 'alert-success'; 
                    $displayText = '';

                    // Success Messages
                    switch ($msg) {
                        case 'added': $displayText = "Shuttle successfully added!"; break;
                        case 'updated': $displayText = "Shuttle details updated."; break;
                        case 'inactive': $displayText = "Shuttle deactivated successfully."; break;
                    }

                    // Error Messages
                    if ($err === 'failed') $displayText = "Action failed. Please try again.";
                ?>

                <?php if ($displayText): ?>
                    <div style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
                        <i class="fas <?php echo $err ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo htmlspecialchars($displayText); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="card">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Shuttle ID</th>
                            <th>Zone & Drivers</th>
                            <th>Trips Today</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($shuttles->isEmpty()): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:20px; color:#777;">
                                    No shuttles found. Click "Add Shuttle" to start.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($shuttles as $doc): 
                                $s = $doc->data();
                                // Resolve zone name
                                $zoneName = (!empty($s['zone_id']) && isset($zoneMap[$s['zone_id']])) 
                                            ? $zoneMap[$s['zone_id']] 
                                            : '<span style="color:#ccc">Unassigned</span>';
                                
                                // Drivers
                                $driversList = $assignedDrivers[$doc->id()] ?? [];
                                $driverStr = empty($driversList) ? '<span style="color:#ccc">None</span>' : implode(', ', $driversList);

                                // Utilization Metric (Aggregated)
                                $tripsToday = 0;
                                try {
                                    $query = $firestore->database()->collection('Bookings')
                                        ->where('shuttle_id', '=', $doc->id())
                                        ->where('date', '=', $today);
                                    if (method_exists($query, 'count')) {
                                        $agg = $query->count();
                                        if (is_int($agg)) {
                                            $tripsToday = $agg;
                                        } elseif (is_object($agg) && method_exists($agg, 'get')) {
                                            $resArray = $agg->get();
                                            foreach ($resArray as $res) {
                                                if (is_object($res) && method_exists($res, 'get')) {
                                                    $tripsToday = $res->get('count') ?? 0;
                                                }
                                            }
                                        }
                                    } else {
                                        $tripsToday = "-";
                                    }
                                } catch (Exception $e) {
                                    $tripsToday = "-";
                                }
                            ?>
                            <tr>
                                <td style="font-weight:600; color:var(--primary-blue);">
                                    <i class="fas fa-van-shuttle"></i> <?= htmlspecialchars($doc->id()) ?>
                                </td>
                                
                                <td>
                                    <div style="font-weight:600; color:#333;"><?= $zoneName ?></div>
                                    <div style="font-size:0.8rem; color:#888;"><i class="fas fa-id-badge"></i> <?= $driverStr ?></div>
                                </td>
                                
                                <td style="font-weight:bold; color:#555; text-align:center;">
                                    <?= $tripsToday ?>
                                </td>
                                
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <?php 
                                            $sStatus = $s['status'] ?? 'active';
                                            $isOnline = $s['is_online'] ?? false;

                                            // 1. Hardware Health Badge
                                            if ($sStatus === 'maintenance') {
                                                echo '<span class="badge" style="background:#f39c12; color:white; border-radius:6px; font-size:0.7rem; padding:2px 8px; width:fit-content;"><i class="fas fa-tools"></i> MAINTENANCE</span>';
                                            } elseif ($sStatus === 'inactive') {
                                                echo '<span class="badge" style="background:#e74c3c; color:white; border-radius:6px; font-size:0.7rem; padding:2px 8px; width:fit-content;"><i class="fas fa-ban"></i> INACTIVE</span>';
                                            } else {
                                                echo '<span class="badge" style="background:#2ecc71; color:white; border-radius:6px; font-size:0.7rem; padding:2px 8px; width:fit-content;"><i class="fas fa-check-circle"></i> ACTIVE</span>';
                                            }

                                            // 2. Connection Badge
                                            if ($sStatus !== 'inactive') {
                                                if ($isOnline) {
                                                    echo '<span style="font-size:0.75rem; color:var(--success); font-weight:600;"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Live Online</span>';
                                                } else {
                                                    echo '<span style="font-size:0.75rem; color:#999; font-weight:600;"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Parked / Offline</span>';
                                                }
                                            }
                                        ?>
                                    </div>
                                </td>
                                
                                <td>
                                    <a href="edit_shuttle.php?id=<?= $doc->id() ?>" class="btn" style="padding:5px 10px; font-size:0.8rem; border-radius:8px;" title="Edit Details">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <?php if (($s['status'] ?? '') !== 'maintenance' && ($s['status'] ?? '') !== 'inactive'): ?>
                                        <form method="POST" action="process_shuttle_status.php" style="display:inline;">
                                            <input type="hidden" name="shuttle_id" value="<?= $doc->id() ?>">
                                            <input type="hidden" name="action" value="maintenance">
                                            <button type="submit" class="btn" style="background:#f39c12; color:white; padding:5px 10px; font-size:0.8rem; border:none; cursor:pointer; border-radius:8px;" onclick="return confirm('Send to Maintenance? This forces the vehicle offline and removes it from automated dispatch.')" title="Send to Maintenance">
                                                <i class="fas fa-tools"></i>
                                            </button>
                                        </form>
                                    <?php elseif (($s['status'] ?? '') === 'maintenance'): ?>
                                        <form method="POST" action="process_shuttle_status.php" style="display:inline;">
                                            <input type="hidden" name="shuttle_id" value="<?= $doc->id() ?>">
                                            <input type="hidden" name="action" value="active">
                                            <button type="submit" class="btn" style="background:var(--success); color:white; padding:5px 10px; font-size:0.8rem; border:none; cursor:pointer; border-radius:8px;" onclick="return confirm('Return to Active fleet?')" title="Restore to Active">
                                                <i class="fas fa-check-double"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>
<script>
    const zoneMap = <?= json_encode($zoneMap) ?>;
    let map;
    const fleetMarkers = {};
    let infoWindow;

    const firebaseConfig = {
        apiKey: "<?= MAPS_API_KEY ?>",
        authDomain: "<?= FIREBASE_AUTH_DOMAIN ?>",
        projectId: "<?= FIREBASE_PROJECT_ID ?>",
        storageBucket: "<?= FIREBASE_STORAGE_BUCKET ?>",
        messagingSenderId: "<?= FIREBASE_MESSAGING_SENDER_ID ?>",
        appId: "<?= FIREBASE_APP_ID ?>"
    };

    // Toggle logic for the Quick Guide
function toggleLegend() {
    const content = document.getElementById('legendContent');
    const icon = document.getElementById('legendIcon');
    
    content.classList.toggle('show');
    
    // Flip the arrow icon up or down
    if (content.classList.contains('show')) {
        icon.style.transform = "rotate(180deg)";
    } else {
        icon.style.transform = "rotate(0deg)";
    }
}

    async function initMap() {
        if (!document.getElementById("fleetRadarMap")) return;
        document.getElementById("fleetRadarMap").innerHTML = "";

        const defaultCenter = { lat: 3.1592, lng: 101.7036 }; 
        
        const grayscaleStyles = [
            { elementType: "geometry", stylers: [{ color: "#f5f5f5" }] },
            { elementType: "labels.icon", stylers: [{ visibility: "off" }] },
            { elementType: "labels.text.fill", stylers: [{ color: "#616161" }] },
            { elementType: "labels.text.stroke", stylers: [{ color: "#f5f5f5" }] },
            { featureType: "administrative.land_parcel", elementType: "labels.text.fill", stylers: [{ color: "#bdbdbd" }] },
            { featureType: "poi", elementType: "geometry", stylers: [{ color: "#eeeeee" }] },
            { featureType: "poi", elementType: "labels.text.fill", stylers: [{ color: "#757575" }] },
            { featureType: "poi.park", elementType: "geometry", stylers: [{ color: "#e5e5e5" }] },
            { featureType: "poi.park", elementType: "labels.text.fill", stylers: [{ color: "#9e9e9e" }] },
            { featureType: "road", elementType: "geometry", stylers: [{ color: "#ffffff" }] },
            { featureType: "road.arterial", elementType: "labels.text.fill", stylers: [{ color: "#757575" }] },
            { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#dadada" }] },
            { featureType: "road.highway", elementType: "labels.text.fill", stylers: [{ color: "#616161" }] },
            { featureType: "road.local", elementType: "labels.text.fill", stylers: [{ color: "#9e9e9e" }] },
            { featureType: "transit.line", elementType: "geometry", stylers: [{ color: "#e5e5e5" }] },
            { featureType: "transit.station", elementType: "geometry", stylers: [{ color: "#eeeeee" }] },
            { featureType: "water", elementType: "geometry", stylers: [{ color: "#c9c9c9" }] },
            { featureType: "water", elementType: "labels.text.fill", stylers: [{ color: "#9e9e9e" }] }
        ];

        map = new google.maps.Map(document.getElementById("fleetRadarMap"), {
            center: defaultCenter,
            zoom: 14,
            mapId: "DEMO_MAP_ID",
            mapTypeControl: false,
            streetViewControl: false
        });

        infoWindow = new google.maps.InfoWindow();
        if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();

        db.collection('Shuttles').onSnapshot(snapshot => {
            const activeIds = new Set();

            snapshot.forEach(doc => {
                const data = doc.data();
                activeIds.add(doc.id);
                
                const lat = data.current_lat ?? null;
                const lng = data.current_lng ?? null;

                if (lat !== null && lng !== null) {
                    const pos = { lat, lng };
                    const zoneId = data.zone_id ?? '';
                    const zName = zoneMap[zoneId] || 'Unassigned';
                    
                    const statusStr = data.status || 'active';
                    const isOnline = data.is_online || false;
                    
                    let fillColor = '#9e9e9e'; 
                    let strokeColor = '#ffffff';
                    let opacity = 0.6;
                    let badgeHtml = '<span style="background:#999; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">OFFLINE</span>';

                    // Determine map colors based on 3-layer logic
                    if (statusStr === 'inactive') {
                        fillColor = '#e74c3c';
                        opacity = 0.4;
                        badgeHtml = '<span style="background:#e74c3c; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">INACTIVE</span>';
                    } else if (statusStr === 'maintenance') {
                        fillColor = '#f39c12';
                        opacity = 1.0;
                        badgeHtml = '<span style="background:#f39c12; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">MAINTENANCE</span>';
                    } else if (isOnline) {
                        fillColor = '#2ecc71';
                        opacity = 1.0;
                        badgeHtml = '<span style="background:#2ecc71; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">ONLINE</span>';
                    }

                    if (!fleetMarkers[doc.id]) {
                        // 2. Build a custom HTML element for the Advanced Marker
                        const markerElement = document.createElement('div');
                        markerElement.style.width = '16px';
                        markerElement.style.height = '16px';
                        markerElement.style.backgroundColor = fillColor;
                        markerElement.style.border = '2px solid #ffffff';
                        markerElement.style.borderRadius = '50%';
                        markerElement.style.opacity = opacity;
                        markerElement.style.boxShadow = '0 2px 4px rgba(0,0,0,0.3)';

                        // 3. Create the Advanced Marker Element
                        const marker = new google.maps.marker.AdvancedMarkerElement({
                            map: map,
                            position: pos,
                            title: doc.id,
                            content: markerElement // Inject the custom HTML circle
                        });

                        marker.addEventListener('gmp-click', () => {
                            const navUrl = `https://www.google.com/maps/dir/?api=1&destination=$${lat},${lng}`;
                            
                            infoWindow.setContent(`
                                <div style="padding: 12px; font-family: 'Poppins', sans-serif; min-width: 200px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:8px;">
                                        <span style="font-weight:700; color:#2c3e50; font-size:1rem;"><i class="fas fa-bus"></i> ${doc.id}</span>
                                        ${badgeHtml}
                                    </div>
                                    
                                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 12px;">
                                        <i class="fas fa-map-marker-alt"></i> <b>Zone:</b> ${zName}<br>
                                        <i class="fas fa-compass"></i> <b>Coords:</b> ${lat.toFixed(4)}, ${lng.toFixed(4)}
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                        <a href="${navUrl}" target="_blank" style="text-decoration:none; background:#3498db; color:white; border-radius:6px; padding: 8px; font-size:0.75rem; text-align:center; font-weight:600;">
                                            <i class="fas fa-location-arrow"></i> Navigate
                                        </a>

                                        <form action="process_shuttle_status.php" method="POST" style="margin:0;">
                                            <input type="hidden" name="shuttle_id" value="${doc.id}">
                                            <input type="hidden" name="action" value="${statusStr === 'maintenance' ? 'active' : 'maintenance'}">
                                            <button type="submit" ${statusStr === 'inactive' ? 'disabled' : ''} style="width:100%; height:100%; background:${statusStr === 'maintenance' ? '#2ecc71' : (statusStr === 'inactive' ? '#ccc' : '#f39c12')}; color:white; border:none; border-radius:6px; padding: 8px; cursor:${statusStr === 'inactive' ? 'not-allowed' : 'pointer'}; font-size:0.75rem; font-family:inherit; font-weight:600;">
                                                <i class="fas ${statusStr === 'maintenance' ? 'fa-check' : 'fa-tools'}"></i> ${statusStr === 'maintenance' ? 'Restore' : 'Maint.'}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            `);
                            infoWindow.open(map, marker);
                        });
                        fleetMarkers[doc.id] = marker;

                        if (Object.keys(fleetMarkers).length === 1) {
                            map.setCenter(pos);
                        }
                    } else {
                        fleetMarkers[doc.id].position = pos; // Note: No longer .setPosition()
                        fleetMarkers[doc.id].content.style.backgroundColor = fillColor;
                        fleetMarkers[doc.id].content.style.opacity = opacity;
                    }
                }
            });

            for (const id in fleetMarkers) {
                if (!activeIds.has(id)) {
                    fleetMarkers[id].map = null;
                    delete fleetMarkers[id];
                }
            }
        });
    }
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= MAPS_API_KEY ?>&libraries=marker&callback=initMap&loading=async"></script>

</body>
</html>