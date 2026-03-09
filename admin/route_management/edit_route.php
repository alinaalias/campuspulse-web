<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$routeId = $_GET['id'] ?? '';
if (!$routeId) { header('Location: routes_management.php?err=missing_id'); exit(); }

$routeRef = $firestore->database()->collection('Routes')->document($routeId);
$routeSnap = $routeRef->snapshot();

if (!$routeSnap->exists()) { header('Location: routes_management.php?err=not_found'); exit(); }
$route = $routeSnap->data();

$zones = []; foreach ($firestore->database()->collection('Zones')->where('status', '=', 'active')->documents() as $z) { $zones[] = $z->data(); }
$stops = []; foreach ($firestore->database()->collection('Stops')->where('status', '=', 'active')->documents() as $s) { $stops[] = $s->data(); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_name      = trim($_POST['route_name']);
    $zone_id         = $_POST['zone_id'] ?? '';
    $direction       = $_POST['direction'] ?? '';
    $service_type    = $_POST['service_type'] ?? '';
    $start_stop_id   = $_POST['start_stop_id'] ?? '';
    $end_stop_id     = $_POST['end_stop_id'] ?? '';
    $stop_ids        = $_POST['stop_ids'] ?? [];

    if (!$route_name || !$zone_id || !$direction || !$service_type || !$start_stop_id || !$end_stop_id || count($stop_ids) < 2) {
        $error = "All fields are required and at least 2 stops must be selected.";
    } elseif ($start_stop_id === $end_stop_id) {
        $error = "Start and End stop cannot be the same.";
    } else {
        try {
            $routeRef->update([
                ['path' => 'route_name',    'value' => $route_name],
                ['path' => 'zone_id',       'value' => $zone_id],
                ['path' => 'direction',     'value' => $direction],
                ['path' => 'service_type',  'value' => $service_type],
                ['path' => 'start_stop_id', 'value' => $start_stop_id],
                ['path' => 'end_stop_id',   'value' => $end_stop_id],
                ['path' => 'stop_ids',      'value' => array_values($stop_ids)],
                ['path' => 'updated_at',    'value' => date('Y-m-d H:i:s')]
            ]);
            header('Location: routes_management.php?msg=route_updated');
            exit();
        } catch (Exception $e) {
            $error = "Failed to update route: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Route - CampusPulse</title>
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
            const zones = JSON.parse(el.dataset.zones || '[]');
            const cb = el.querySelector('input');
            if (zones.includes(zoneId)) { el.style.display = 'block'; } else { el.style.display = 'none'; cb.checked = false; }
        });
        document.querySelectorAll('.stop-select option').forEach(opt => {
            if (opt.value === "") return;
            const zones = JSON.parse(opt.dataset.zones || '[]');
            opt.style.display = zones.includes(zoneId) ? 'block' : 'none';
        });
    }
    window.onload = function () { filterStopsByZone("<?= $route['zone_id'] ?>"); };
    </script>
</head>
<body>
<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>
    <div id="content">
        <?php include '../../layout/header.php'; ?>
        <div class="main-content">
            <div class="card" style="max-width: 700px; margin: 0 auto;">
                <h2 style="color:var(--primary-blue); margin-bottom: 10px;">Edit Route</h2>
                <p style="color:#777; margin-bottom:20px;">ID: <strong><?= htmlspecialchars($routeId) ?></strong></p>

                <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="POST">
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Route Name</label>
                        <input type="text" name="route_name" class="form-control" value="<?= htmlspecialchars($route['route_name']) ?>" required>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div>
                            <label style="font-weight:600;">Zone</label>
                            <select name="zone_id" class="form-control" required onchange="filterStopsByZone(this.value)">
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
                                <option value="scheduled" <?= $route['service_type']=='scheduled'?'selected':'' ?>>Scheduled</option>
                                <option value="on_demand" <?= $route['service_type']=='on_demand'?'selected':'' ?>>On Demand</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:15px;">
                        <label style="font-weight:600;">Direction</label>
                        <select name="direction" class="form-control" required>
                            <option value="to_campus" <?= $route['direction']=='to_campus'?'selected':'' ?>>To Campus</option>
                            <option value="from_campus" <?= $route['direction']=='from_campus'?'selected':'' ?>>From Campus</option>
                        </select>
                    </div>

                    <h4 style="margin-top:25px; border-bottom:2px solid var(--accent-yellow); padding-bottom:5px; color:var(--primary-blue);">Route Path</h4>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:15px;">
                        <div>
                            <label style="font-size:0.9rem;">Start Point</label>
                            <select name="start_stop_id" class="stop-select form-control" required>
                                <?php foreach ($stops as $s): ?>
                                    <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>' <?= $route['start_stop_id']===$s['stop_id']?'selected':'' ?>>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.9rem;">End Point</label>
                            <select name="end_stop_id" class="stop-select form-control" required>
                                <?php foreach ($stops as $s): ?>
                                    <option value="<?= $s['stop_id'] ?>" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>' <?= $route['end_stop_id']===$s['stop_id']?'selected':'' ?>>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label style="font-weight:600; margin-top:20px; display:block;">Stops Covered</label>
                    <div style="border:1px solid #ccc; border-radius:4px; max-height:200px; overflow-y:auto; background:#f9f9f9;">
                        <?php foreach ($stops as $s): ?>
                            <div class="stop-item" data-zones='<?= json_encode($s['zone_ids'] ?? []) ?>'>
                                <label style="display:flex; align-items:center; cursor:pointer; width:100%;">
                                    <input type="checkbox" name="stop_ids[]" value="<?= $s['stop_id'] ?>" style="width:auto; margin-right:10px;" <?= in_array($s['stop_id'], $route['stop_ids'] ?? []) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:30px;">
                        <a href="routes_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Route</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>