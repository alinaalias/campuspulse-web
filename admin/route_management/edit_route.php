<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$routeId = $_GET['id'] ?? '';
if (!$routeId) {
    header('Location: routes_management.php?err=missing_id');
    exit();
}

$routeRef = $firestore->collection('Routes')->document($routeId);
$routeSnap = $routeRef->snapshot();

if (!$routeSnap->exists()) {
    header('Location: routes_management.php?err=not_found');
    exit();
}
$route = $routeSnap->data();

$zones = [];
foreach ($firestore->collection('Zones')->where('status', '=', 'active')->documents() as $z) {
    $zones[] = $z->data();
}
$stops = [];
foreach ($firestore->collection('Stops')->where('status', '=', 'active')->documents() as $s) {
    $stops[] = $s->data();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_name = trim($_POST['route_name']);
    $zone_id = $_POST['zone_id'] ?? '';
    $direction = $_POST['direction'] ?? '';
    $service_type = $_POST['service_type'] ?? '';

    // NEW: Expecting optimized JSON data, just like add_route.php
    $final_route_data = $_POST['final_route_data'] ?? '';
    $stopDataObjects = json_decode($final_route_data, true);

    if (!$route_name || !$zone_id || !$direction || !$service_type || empty($stopDataObjects) || count($stopDataObjects) < 2) {
        $error = "All fields required. Make sure to generate and save the optimized route.";
    } else {
        try {
            $start_stop_id = $stopDataObjects[0]['stop_id'];
            $end_stop_id = $stopDataObjects[count($stopDataObjects) - 1]['stop_id'];

            if ($start_stop_id === $end_stop_id) {
                throw new Exception("Start and End stops cannot be identical.");
            }

            $routeRef->update([
                ['path' => 'route_name', 'value' => $route_name],
                ['path' => 'zone_id', 'value' => $zone_id],
                ['path' => 'direction', 'value' => $direction],
                ['path' => 'service_type', 'value' => $service_type],
                ['path' => 'start_stop_id', 'value' => $start_stop_id],
                ['path' => 'end_stop_id', 'value' => $end_stop_id],
                ['path' => 'stop_ids', 'value' => $stopDataObjects],
                ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
            ]);
            header('Location: routes_management.php?msg=route_updated');
            exit();
        } catch (Exception $e) {
            $error = "Failed to update route: " . $e->getMessage();
        }
    }
}

// Prepare existing waypoints (excluding start and end) for pre-population
$existingStops = $route['stop_ids'] ?? [];
$waypointsOnly = [];
if (count($existingStops) > 2) {
    // Slice off the first and last elements
    $waypointsOnly = array_slice($existingStops, 1, -1);
}

$pageTitle = 'Edit Route';
$depth = '../../';
include $depth . 'layout/admin/header.php';
?>

<style>
    .stop-item {
        display: none;
        padding: 8px;
        border-bottom: 1px solid #eee;
    }

    .stop-item:last-child {
        border-bottom: none;
    }

    .locked-input {
        background-color: #f1f1f1 !important;
        pointer-events: none;
        opacity: 0.7;
        border-color: #ccc !important;
    }

    option:disabled {
        color: #ccc;
        font-style: italic;
    }
</style>
<script
    src="https://maps.googleapis.com/maps/api/js?key=<?php echo MAPS_API_KEY; ?>&libraries=places&loading=async"></script>
<script>
    function updateAvailableStops() {
        const allSelects = document.querySelectorAll('.stop-select');
        const selectedValues = Array.from(allSelects).map(sel => sel.value).filter(val => val !== "");

        allSelects.forEach(select => {
            Array.from(select.options).forEach(option => {
                if (option.value === "") return;
                if (selectedValues.includes(option.value) && select.value !== option.value) {
                    option.disabled = true;
                } else {
                    option.disabled = false;
                }
            });
        });
    }

    function filterStopsByZone(zoneId) {
        document.querySelectorAll('.stop-select option:not([value=""])').forEach(opt => {
            if (opt.dataset.zones?.includes(zoneId) || opt.dataset.isHub === "true") {
                opt.style.display = 'block';
            } else {
                opt.style.display = 'none';
            }
        });
        updateAvailableStops();
    }

    function handleDirectionChange() {
        const direction = document.getElementById('directionSelect').value;
        const startSel = document.getElementById('startStopSelect');
        const endSel = document.getElementById('endStopSelect');

        let campusStopId = "";
        Array.from(startSel.options).forEach(opt => {
            const text = opt.text.toLowerCase();
            if (text.includes('miit') || text.includes('unikl')) campusStopId = opt.value;
        });

        startSel.classList.remove('locked-input');
        endSel.classList.remove('locked-input');
        if (startSel.value === campusStopId) startSel.value = "";
        if (endSel.value === campusStopId) endSel.value = "";

        if (!campusStopId) { updateAvailableStops(); return; }

        if (direction === 'to_campus') {
            endSel.value = campusStopId;
            endSel.classList.add('locked-input');
        } else if (direction === 'from_campus') {
            startSel.value = campusStopId;
            startSel.classList.add('locked-input');
        }
        updateAvailableStops();
    }

    function addWaypoint(preSelectedValue = "") {
        const zoneId = document.querySelector('select[name="zone_id"]').value;
        if (!zoneId && !preSelectedValue) { alert("Select a zone first"); return; }

        const wpContainer = document.getElementById('waypointsContainer');
        const div = document.createElement('div');
        div.className = "waypoint-item";
        div.style.marginBottom = "10px";
        div.style.display = "flex";
        div.style.gap = "10px";

        div.innerHTML = `
            <select class="stop-select form-control waypoint-select" style="margin-bottom:0;" required onchange="updateAvailableStops()">
                <option value="">-- Select Waypoint --</option>
                <?php foreach ($stops as $s):
                    $isHub = (stripos($s['name'], 'unikl') !== false || stripos($s['name'], 'miit') !== false) ? 'true' : 'false';
                    ?>
                    <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>' data-lat="<?= $s['lat'] ?? '' ?>" data-lng="<?= $s['lng'] ?? '' ?>" data-is-hub="<?= $isHub ?>">
                        <?= htmlspecialchars($s['name'] ?? ($s['stop_name'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn danger" onclick="this.parentElement.remove(); updateAvailableStops();"><i class="fas fa-trash"></i></button>
            `;
        wpContainer.appendChild(div);

        const selectEl = div.querySelector('select');
        if (preSelectedValue) {
            selectEl.value = preSelectedValue;
        }

        div.querySelectorAll('option:not([value=""])').forEach(opt => {
            if (opt.dataset.zones?.includes(zoneId) || opt.dataset.isHub === "true") {
                opt.style.display = 'block';
            } else {
                opt.style.display = 'none';
            }
        });
        updateAvailableStops();
    }

    function optimizeRoute() {
        const startSel = document.getElementById('startStopSelect');
        const endSel = document.getElementById('endStopSelect');
        const waypointSels = document.querySelectorAll('.waypoint-select');

        if (!startSel.value || !endSel.value) {
            alert("Please select Start and End stops."); return;
        }

        const dirsService = new google.maps.DirectionsService();

        function extractLoc(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            return {
                id: opt.value, name: opt.text.trim(),
                lat: parseFloat(opt.dataset.lat), lng: parseFloat(opt.dataset.lng)
            };
        }

        const startNode = extractLoc(startSel);
        const endNode = extractLoc(endSel);
        const wpNodes = Array.from(waypointSels).map(s => extractLoc(s));

        const mapWaypoints = wpNodes.map(node => ({
            location: new google.maps.LatLng(node.lat, node.lng),
            stopover: true
        }));

        if (wpNodes.some(n => isNaN(n.lat) || isNaN(n.lng)) || isNaN(startNode.lat) || isNaN(endNode.lat)) {
            alert("Error: Missing latitude/longitude for some selected stops."); return;
        }

        const request = {
            origin: new google.maps.LatLng(startNode.lat, startNode.lng),
            destination: new google.maps.LatLng(endNode.lat, endNode.lng),
            waypoints: mapWaypoints, optimizeWaypoints: true, travelMode: 'DRIVING'
        };

        dirsService.route(request, (result, status) => {
            if (status === 'OK') {
                const route = result.routes[0];
                const order = route.waypoint_order;

                const container = document.getElementById('waypointsContainer');
                const wpContainers = Array.from(container.children);
                container.innerHTML = "";
                order.forEach(idx => { container.appendChild(wpContainers[idx]); });

                let currentOffsetMins = 0;
                let finalSequenceObjects = [];
                let previewHTML = "<div style='font-size:0.9rem; margin-top:10px;'><h5 style='color:var(--primary-blue);'>Optimized Route Execution</h5>";

                finalSequenceObjects.push({ stop_id: startNode.id, offset: 0 });
                previewHTML += `<div><i class='fas fa-map-marker-alt' style='color:#2ecc71'></i> 0m : ${startNode.name}</div>`;

                let orderedWpNodes = order.map(idx => wpNodes[idx]);

                route.legs.forEach((leg, i) => {
                    currentOffsetMins += Math.round(leg.duration.value / 60);

                    if (i < orderedWpNodes.length) {
                        let stopObj = orderedWpNodes[i];
                        finalSequenceObjects.push({ stop_id: stopObj.id, offset: currentOffsetMins });
                        previewHTML += `<div style='margin-left:15px; border-left:2px solid #ccc; padding-left:10px; color:#888;'>| <i>${leg.duration.text}</i></div>`;
                        previewHTML += `<div><i class='fas fa-stop-circle' style='color:var(--primary-blue)'></i> ${currentOffsetMins}m : ${stopObj.name}</div>`;
                    } else {
                        finalSequenceObjects.push({ stop_id: endNode.id, offset: currentOffsetMins });
                        previewHTML += `<div style='margin-left:15px; border-left:2px solid #ccc; padding-left:10px; color:#888;'>| <i>${leg.duration.text}</i></div>`;
                        previewHTML += `<div><i class='fas fa-flag-checkered' style='color:#e74c3c'></i> ${currentOffsetMins}m : ${endNode.name}</div>`;
                    }
                });

                previewHTML += "</div>";
                document.getElementById('optimizationPreview').innerHTML = previewHTML;
                document.getElementById('stopOffsetsInput').value = JSON.stringify(finalSequenceObjects);
            } else {
                alert("Directions request failed due to " + status);
            }
        });
    }

    window.onload = function () {
        // 1. Filter dropdowns based on existing zone
        filterStopsByZone("<?= $route['zone_id'] ?>");
        // 2. Lock the start/end if direction is applied
        handleDirectionChange();

        // 3. Pre-populate waypoints from database
        const existingWaypoints = <?= json_encode($waypointsOnly) ?>;
        existingWaypoints.forEach(wp => {
            const stopId = wp.stop_id || wp; // Handle both object and string structures
            addWaypoint(stopId);
        });
    };
</script>

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <h2 style="color:var(--primary-blue); margin-bottom: 10px;">Edit Route</h2>
    <p style="color:#777; margin-bottom:20px;">ID: <strong><?= htmlspecialchars($routeId) ?></strong>
    </p>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
        <div style="margin-bottom:15px;">
            <label style="font-weight:600;">Route Name</label>
            <input type="text" name="route_name" class="form-control"
                value="<?= htmlspecialchars($route['route_name']) ?>" required>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            <div>
                <label style="font-weight:600;">Zone</label>
                <select name="zone_id" class="form-control" required
                    onchange="filterStopsByZone(this.value); document.getElementById('waypointsContainer').innerHTML='';">
                    <?php foreach ($zones as $z): ?>
                        <option value="<?= $z['zone_id'] ?>" <?= $route['zone_id'] === $z['zone_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($z['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-weight:600;">Service Type</label>
                <select name="service_type" class="form-control" required>
                    <option value="scheduled" <?= $route['service_type'] == 'scheduled' ? 'selected' : '' ?>>
                        Scheduled</option>
                    <option value="on_demand" <?= $route['service_type'] == 'on_demand' ? 'selected' : '' ?>>On
                        Demand</option>
                </select>
            </div>
        </div>

        <div style="margin-top:15px;">
            <label style="font-weight:600;">Direction</label>
            <select name="direction" id="directionSelect" class="form-control" required
                onchange="handleDirectionChange()">
                <option value="to_campus" <?= $route['direction'] == 'to_campus' ? 'selected' : '' ?>>To Campus
                </option>
                <option value="from_campus" <?= $route['direction'] == 'from_campus' ? 'selected' : '' ?>>From
                    Campus</option>
            </select>
        </div>

        <h4
            style="margin-top:25px; border-bottom:2px solid var(--accent-yellow); padding-bottom:5px; color:var(--primary-blue);">
            Smart Route Builder</h4>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:15px; margin-bottom: 20px;">
            <div>
                <label style="font-size:0.9rem;">Start Point</label>
                <select name="start_stop_id" id="startStopSelect" class="stop-select form-control"
                    style="border: 1px solid #2ecc71;" required onchange="updateAvailableStops()">
                    <option value="">-- Select Start --</option>
                    <?php foreach ($stops as $s):
                        $isHub = (stripos($s['name'], 'unikl') !== false || stripos($s['name'], 'miit') !== false) ? 'true' : 'false';
                        ?>
                        <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>'
                            data-lat="<?= $s['lat'] ?? '' ?>" data-lng="<?= $s['lng'] ?? '' ?>" data-is-hub="<?= $isHub ?>"
                            <?= $route['start_stop_id'] === $s['stop_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:0.9rem;">End Point</label>
                <select name="end_stop_id" id="endStopSelect" class="stop-select form-control"
                    style="border: 1px solid #e74c3c;" required onchange="updateAvailableStops()">
                    <option value="">-- Select End --</option>
                    <?php foreach ($stops as $s):
                        $isHub = (stripos($s['name'], 'unikl') !== false || stripos($s['name'], 'miit') !== false) ? 'true' : 'false';
                        ?>
                        <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>'
                            data-lat="<?= $s['lat'] ?? '' ?>" data-lng="<?= $s['lng'] ?? '' ?>" data-is-hub="<?= $isHub ?>"
                            <?= $route['end_stop_id'] === $s['stop_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="background: #f9f9f9; border: 1px solid #eee; padding: 15px; border-radius: 8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:600; margin:0;"><i class="fas fa-route"
                        style="color:var(--primary-blue);"></i> Intermediate Waypoints</label>
                <button type="button" class="btn" onclick="addWaypoint()"
                    style="background:#eef2f7; color:var(--primary-blue); padding:5px 10px; font-size:0.8rem;"><i
                        class="fas fa-plus"></i> Add Waypoint</button>
            </div>
            <div id="waypointsContainer"></div>
            <small style="color:#777; display:block; margin-top:5px;">* Waypoints will be automatically
                reorganized for the shortest travel time.</small>
        </div>

        <div
            style="margin-top:20px; background: #fffcf0; padding:15px; border: 1px solid var(--accent-yellow); border-radius:8px; text-align:center;">
            <button type="button" class="btn" style="background:var(--accent-yellow); color:#333; font-weight:600;"
                onclick="optimizeRoute()">
                <i class="fas fa-magic"></i> Optimize Sequence & Calculate ETAs
            </button>
            <div id="optimizationPreview" style="text-align:left;"></div>
        </div>

        <input type="hidden" name="final_route_data" id="stopOffsetsInput" required>

        <div style="display:flex; justify-content:space-between; margin-top:30px;">
            <a href="routes_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
            <button type="submit" class="btn btn-primary"
                onclick="return document.getElementById('stopOffsetsInput').value !== '' ? true : (alert('Please click Optimize Sequence first!'), false)">Save
                Optimized Route</button>
        </div>
    </form>
</div>
<?php include $depth . 'layout/admin/footer.php'; ?>