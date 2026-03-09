<?php
session_start();
require_once '../../config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../../login.php'); exit(); }

$zones = []; foreach ($firestore->database()->collection('Zones')->where('status', '=', 'active')->documents() as $z) { $zones[] = $z->data(); }
$stops = []; foreach ($firestore->database()->collection('Stops')->where('status', '=', 'active')->documents() as $s) { $stops[] = $s->data(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_name = trim($_POST['route_name']);
    $zone_id = $_POST['zone_id'] ?? '';
    $direction = $_POST['direction'] ?? '';
    $service_type = $_POST['service_type'] ?? '';
    $start_stop_id = $_POST['start_stop_id'] ?? '';
    $end_stop_id = $_POST['end_stop_id'] ?? '';
    $stop_ids = $_POST['stop_ids'] ?? [];

    if (!$route_name || !$zone_id || !$direction || !$service_type || !$start_stop_id || !$end_stop_id || count($stop_ids) < 2) {
        $error = "All fields required. Select at least 2 stops.";
    } elseif ($start_stop_id === $end_stop_id) {
        $error = "Start and End stop cannot be the same.";
    } else {
        try {
            $routeId = generateCustomId('routes', 'ROUTE', $firestore);
            $firestore->database()->collection('Routes')->document($routeId)->set([
                'route_id' => $routeId, 'route_name' => $route_name, 'zone_id' => $zone_id,
                'direction' => $direction, 'service_type' => $service_type,
                'start_stop_id' => $start_stop_id, 'end_stop_id' => $end_stop_id,
                'stop_ids' => array_values($stop_ids), 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')
            ]);
            // Redirect with Success Message
            header('Location: routes_management.php?msg=route_added'); 
            exit();
        } catch (Exception $e) {
            $error = "Failed to add route: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Route</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
    <style>
        .stop-item { display:none; padding: 8px; border-bottom:1px solid #eee; }
        .stop-item:last-child { border-bottom: none; }
    </style>
    <script>
    function filterStopsByZone(zoneId) {
        document.querySelectorAll('.stop-item').forEach(el => {
            const zones = JSON.parse(el.dataset.zones);
            const cb = el.querySelector('input');
            if (zones.includes(zoneId)) { el.style.display = 'block'; } 
            else { el.style.display = 'none'; cb.checked = false; }
        });
        document.querySelectorAll('.stop-select').forEach(sel => {
            sel.querySelectorAll('option').forEach(opt => {
                opt.style.display = opt.dataset.zones?.includes(zoneId) ? 'block' : 'none';
            });
            sel.value = ""; 
        });
    }
    </script>
</head>
<body>
<div class="wrapper">
    <?php $depth = '../../'; include '../../layout/sidebar.php'; ?>
    <div id="content">
        <?php include '../../layout/header.php'; ?>
        <div class="main-content">
            <div class="card" style="max-width: 700px; margin: 0 auto;">
                <h2 style="color:var(--primary-blue); margin-bottom:20px;">Create New Route</h2>
                <?php if (!empty($error)): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="POST">
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Route Name</label>
                        <input type="text" name="route_name" class="form-control" placeholder="e.g. Red Line Loop" required>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div>
                            <label style="font-weight:600;">Zone</label>
                            <select name="zone_id" class="form-control" required onchange="filterStopsByZone(this.value)">
                                <option value="">-- Select Zone --</option>
                                <?php foreach ($zones as $z): ?>
                                    <option value="<?= $z['zone_id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600;">Service Type</label>
                            <select name="service_type" class="form-control" required>
                                <option value="scheduled">Scheduled</option>
                                <option value="on_demand">On Demand</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom:15px; margin-top:15px;">
                        <label style="font-weight:600;">Direction</label>
                        <select name="direction" class="form-control" required>
                            <option value="">-- Select Direction --</option>
                            <option value="to_campus">To Campus</option>
                            <option value="from_campus">From Campus</option>
                        </select>
                    </div>

                    <h4 style="margin-top:20px; border-bottom:2px solid var(--accent-yellow); padding-bottom:5px;">Route Path</h4>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div>
                            <label style="font-size:0.9rem;">Start Point</label>
                            <select name="start_stop_id" class="stop-select form-control" required>
                                <option value="">-- Select Start --</option>
                                <?php foreach ($stops as $s): ?>
                                    <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>'>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.9rem;">End Point</label>
                            <select name="end_stop_id" class="stop-select form-control" required>
                                <option value="">-- Select End --</option>
                                <?php foreach ($stops as $s): ?>
                                    <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>'>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label style="font-weight:600; margin-top:15px; display:block;">Select All Stops Covered</label>
                    <div style="border:1px solid #ccc; border-radius:4px; max-height:200px; overflow-y:auto; background:#f9f9f9;">
                        <?php foreach ($stops as $s): ?>
                            <div class="stop-item" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>'>
                                <label style="display:flex; align-items:center; cursor:pointer;">
                                    <input type="checkbox" name="stop_ids[]" value="<?= $s['stop_id'] ?>" style="width:auto; margin-right:10px;">
                                    <?= htmlspecialchars($s['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small style="color:#777;">* Only stops belonging to the selected zone will appear.</small>

                    <div style="display:flex; justify-content:space-between; margin-top:30px;">
                        <a href="routes_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Route</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>