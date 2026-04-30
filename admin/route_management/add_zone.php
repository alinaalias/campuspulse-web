<?php
session_start();
require_once '../../config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    if (!$name) {
        $error = 'Zone name is required.';
    } else {
        try {
            $zoneId = generateCustomId('zones', 'ZONE', $firestore);
            $firestore->database()->collection('Zones')->document($zoneId)->set([
                'zone_id' => $zoneId,
                'name' => $name,
                'description' => $description,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            header('Location: routes_management.php?msg=zone_added#section-zones');
            exit();
        } catch (Exception $e) {
            $error = "Failed to add zone: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Add Zone';
$depth = '../../';
include $depth . 'layout/admin_header.php';
?>

<div class="card" style="max-width: 500px; margin: 0 auto;">
    <h2 style="color:var(--primary-blue);">Add Operational Zone</h2>
    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
        <label style="font-weight:600;">Zone Name</label>
        <input type="text" name="name" class="form-control" placeholder="e.g. City Campus" required>

        <label style="font-weight:600; margin-top:10px; display:block;">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Description of the area..."></textarea>

        <div style="display:flex; justify-content:space-between; margin-top:30px;">
            <a href="routes_management.php#section-zones" class="btn" style="background:#eee; color:#333;">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Zone</button>
        </div>
    </form>
</div>
<?php include $depth . 'layout/admin_footer.php'; ?>