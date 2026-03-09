<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$error = '';

// FETCH ZONES
$zones = [];
$zonesSnapshot = $firestore->database()
    ->collection('Zones')
    ->where('status', '=', 'active')
    ->documents();

foreach ($zonesSnapshot as $doc) {
    $zones[] = ['id' => $doc->id(), 'name' => $doc->data()['name'] ?? 'Unnamed Zone'];
}

// HANDLE SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $zoneId = trim($_POST['zone_id'] ?? '');

    if (!$zoneId) {
        $error = "Please select a zone.";
    } else {
        try {
            $shuttleId = generateCustomId('shuttles', 'CPS', $firestore);

            $firestore->database()->collection('Shuttles')->document($shuttleId)->set([
                'shuttle_id' => $shuttleId,
                'zone_id'    => $zoneId,
                'capacity'   => 13,
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            header('Location: shuttles_management.php?msg=added');
            exit();
        } catch (Exception $e) {
            $error = "Failed to add shuttle: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Shuttle - CampusPulse</title>
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
                <h2 style="color:var(--primary-blue); margin-bottom: 20px;">Add New Shuttle</h2>

                <?php if ($error): ?>
                    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Shuttle ID</label>
                        <input type="text" class="form-control" value="Auto-generated (CPSxxx)" disabled style="background:#eee;">
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Assign Zone</label>
                        <select name="zone_id" class="form-control" required>
                            <option value="">-- Select Zone --</option>
                            <?php foreach ($zones as $z): ?>
                                <option value="<?= htmlspecialchars($z['id']) ?>">
                                    <?= htmlspecialchars($z['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:25px;">
                        <label style="font-weight:600;">Capacity</label>
                        <input type="text" class="form-control" value="13 Passengers (Standard)" disabled style="background:#eee;">
                    </div>

                    <div style="display:flex; justify-content:space-between;">
                        <a href="shuttles_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Shuttle</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>