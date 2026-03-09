<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? '';
if (!$id) { header('Location: routes_management.php?err=missing_id'); exit(); }

$ref = $firestore->database()->collection('Zones')->document($id);
$snap = $ref->snapshot();

if (!$snap->exists()) { header('Location: routes_management.php?err=not_found'); exit(); }
$zone = $snap->data();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];

    if ($name) {
        try {
            $ref->update([
                ['path' => 'name', 'value' => $name],
                ['path' => 'description', 'value' => $description],
                ['path' => 'status', 'value' => $status]
            ]);
            header('Location: routes_management.php?msg=zone_updated#section-zones');
            exit();
        } catch (Exception $e) {
            $error = "Failed to update zone: " . $e->getMessage();
        }
    } else {
        $error = "Zone name is required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Zone - CampusPulse</title>
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
                <h2 style="color:var(--primary-blue);">Edit Zone</h2>
                <p style="color:#777; margin-bottom:20px;">ID: <strong><?= htmlspecialchars($zone['zone_id']) ?></strong></p>

                <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="POST">
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Zone Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($zone['name']) ?>" required>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($zone['description'] ?? '') ?></textarea>
                    </div>

                    <div style="margin-bottom:25px;">
                        <label style="font-weight:600;">Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?= $zone['status']==='active'?'selected':'' ?>>Active</option>
                            <option value="inactive" <?= $zone['status']==='inactive'?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:space-between;">
                        <a href="routes_management.php#section-zones" class="btn" style="background:#eee; color:#333;">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Zone</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>