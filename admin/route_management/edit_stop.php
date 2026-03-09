<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$stopId = $_GET['id'] ?? '';
if (!$stopId) { header('Location: routes_management.php?err=missing_id'); exit(); }

$stopRef = $firestore->database()->collection('Stops')->document($stopId);
$stopSnap = $stopRef->snapshot();

if (!$stopSnap->exists()) { header('Location: routes_management.php?err=not_found'); exit(); }
$stop = $stopSnap->data();

$zones = [];
foreach ($firestore->database()->collection('Zones')->where('status', '=', 'active')->documents() as $z) {
    $zones[] = $z->data();
}

$error = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $lat  = floatval($_POST['lat']);
    $lng  = floatval($_POST['lng']);
    $zoneIds = $_POST['zone_ids'] ?? [];

    if (!$name || !$lat || !$lng) {
        $error = 'All fields except zones are required.';
    } else {
        try {
            $stopRef->update([
                ['path' => 'name', 'value' => $name],
                ['path' => 'lat', 'value' => $lat],
                ['path' => 'lng', 'value' => $lng],
                ['path' => 'zone_ids', 'value' => array_values($zoneIds)],
            ]);
            // Redirect immediately with success message and anchor
            header("Location: routes_management.php?msg=stop_updated#section-stops");
            exit();
        } catch (Exception $e) {
            $error = "Failed to update stop: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Stop - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>
<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>
    <div id="content">
        <?php include '../../layout/header.php'; ?>
        <div class="main-content">
            <div class="card" style="max-width: 500px; margin: 0 auto;">
                <h2 style="color:var(--primary-blue);">Edit Bus Stop</h2>
                <p style="color:#777; margin-bottom:20px;">ID: <strong><?= htmlspecialchars($stopId) ?></strong></p>

                <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="POST">
                    <label style="font-weight:600;">Stop Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($stop['name']) ?>" required>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:15px;">
                        <div><label>Latitude</label><input type="number" step="any" name="lat" class="form-control" value="<?= $stop['lat'] ?>" required></div>
                        <div><label>Longitude</label><input type="number" step="any" name="lng" class="form-control" value="<?= $stop['lng'] ?>" required></div>
                    </div>

                    <label style="font-weight:600; margin-top:20px; display:block;">Linked Zones</label>
                    <div style="border:1px solid #ccc; padding:10px; border-radius:4px; max-height:150px; overflow-y:auto; background:#fafafa;">
                        <?php foreach ($zones as $z): ?>
                            <label style="display:block; margin-bottom:8px; cursor:pointer;">
                                <input type="checkbox" name="zone_ids[]" value="<?= $z['zone_id'] ?>" <?= in_array($z['zone_id'], $stop['zone_ids'] ?? []) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($z['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:25px; display:flex; justify-content:space-between;">
                        <a href="routes_management.php#section-stops" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Stop</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>