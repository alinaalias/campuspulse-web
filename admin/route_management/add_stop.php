<?php
session_start();
require_once '../../config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../../login.php'); exit(); }

$zones = [];
foreach ($firestore->database()->collection('Zones')->where('status', '=', 'active')->documents() as $z) { $zones[] = $z->data(); }

$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']); $lat = floatval($_POST['lat']); $lng = floatval($_POST['lng']); $zoneIds = $_POST['zone_ids'] ?? [];
    if (!$name || !$lat || !$lng) { $error = 'Stop name, lat and lng are required.'; } 
    else {
        try {
            $stopId = generateCustomId('stops', 'STOP', $firestore);
            $firestore->database()->collection('Stops')->document($stopId)->set([
                'stop_id' => $stopId, 'name' => $name, 'lat' => $lat, 'lng' => $lng,
                'zone_ids' => array_values($zoneIds), 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')
            ]);
            header('Location: routes_management.php?msg=stop_added#section-stops'); 
            exit();
        } catch (Exception $e) {
            $error = "Failed to add stop: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Stop</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
<div class="wrapper">
    <?php $depth = '../../'; include '../../layout/sidebar.php'; ?>
    <div id="content">
        <?php include '../../layout/header.php'; ?>
        <div class="main-content">
            <div class="card" style="max-width: 500px; margin: 0 auto;">
                <h2 style="color:var(--primary-blue);">Add Bus Stop</h2>
                <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="POST">
                    <label style="font-weight:600;">Stop Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Library Main Entrance" required>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:10px;">
                        <div><label>Latitude</label><input type="number" step="any" name="lat" class="form-control" required></div>
                        <div><label>Longitude</label><input type="number" step="any" name="lng" class="form-control" required></div>
                    </div>

                    <label style="font-weight:600; margin-top:15px; display:block;">Assign Zones</label>
                    <div style="border:1px solid #ccc; padding:10px; border-radius:4px; max-height:150px; overflow-y:auto;">
                        <?php foreach ($zones as $z): ?>
                            <label style="display:block; margin-bottom:5px;">
                                <input type="checkbox" name="zone_ids[]" value="<?= $z['zone_id'] ?>"> 
                                <?= htmlspecialchars($z['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:30px;">
                        <a href="routes_management.php#section-stops" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Stop</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>