<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$shuttleId = $_GET['id'] ?? '';
if (!$shuttleId) { header('Location: shuttles_management.php'); exit(); }

$shuttleRef = $firestore->database()->collection('Shuttles')->document($shuttleId);
$snap = $shuttleRef->snapshot();

if (!$snap->exists()) { header('Location: shuttles_management.php?msg=notfound'); exit(); }
$shuttle = $snap->data();
$error = '';

// FETCH ZONES
$zones = [];
$zonesSnapshot = $firestore->database()->collection('Zones')->where('status', '=', 'active')->documents();
foreach ($zonesSnapshot as $doc) {
    $zones[] = ['id' => $doc->id(), 'name' => $doc->data()['name'] ?? 'Unnamed Zone'];
}

// UPDATE LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $zoneId   = trim($_POST['zone_id'] ?? '');
    $status   = trim($_POST['status'] ?? '');
    $capacity = intval($_POST['capacity'] ?? 0);

    if (!$zoneId) {
        $error = "Zone is required.";
    } elseif ($capacity < 5 || $capacity > 30) {
        $error = "Capacity must be between 5 and 30.";
    } else {
        try {
            $shuttleRef->update([
                ['path' => 'zone_id', 'value' => $zoneId],
                ['path' => 'capacity', 'value' => $capacity],
                ['path' => 'status', 'value' => $status],
                ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
            ]);
            header('Location: shuttles_management.php?msg=updated');
            exit();
        } catch (Exception $e) {
            $error = "Failed to update shuttle: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Shuttle - CampusPulse</title>
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
                <h2 style="color:var(--primary-blue); margin-bottom: 10px;">Edit Shuttle</h2>
                <p style="color:#777; margin-bottom:20px;">ID: <strong><?= htmlspecialchars($shuttleId) ?></strong></p>

                <?php if ($error): ?>
                    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Assigned Zone</label>
                        <select name="zone_id" class="form-control" required>
                            <option value="">-- Select Zone --</option>
                            <?php foreach ($zones as $z): ?>
                                <option value="<?= htmlspecialchars($z['id']) ?>" 
                                    <?= ($shuttle['zone_id'] ?? '') === $z['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($z['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Capacity (Seats)</label>
                        <input type="number" name="capacity" class="form-control" 
                               value="<?= htmlspecialchars($shuttle['capacity']) ?>" min="5" max="30" required>
                    </div>

                    <div style="margin-bottom:25px;">
                        <label style="font-weight:600;">Status</label>
                        <select name="status" class="form-control" required>
                            <option value="active" <?= $shuttle['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $shuttle['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:space-between;">
                        <a href="shuttles_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Shuttle</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>