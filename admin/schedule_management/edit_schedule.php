<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: schedules_management.php');
    exit();
}

$ref = $firestore->database()->collection('Schedules')->document($id);
$snap = $ref->snapshot();
if (!$snap->exists()) {
    header('Location: schedules_management.php?msg=notfound');
    exit();
}
$schedule = $snap->data();

$routeSnap = $firestore->database()->collection('Routes')->document($schedule['route_id'])->snapshot();
if (!$routeSnap->exists()) {
    header('Location: schedules_management.php?msg=route_missing');
    exit();
}
$route = $routeSnap->data();

// FETCH ACTIVE SHUTTLES
$shuttles = [];
foreach ($firestore->database()->collection('Shuttles')->where('status', '=', 'active')->documents() as $s) {
    $shuttles[] = $s->id();
}

// FETCH ACTIVE DRIVERS
$drivers = [];
foreach ($firestore->database()->collection('Staffs')->where('role', '=', 'driver')->where('status', '=', 'active')->documents() as $d) {
    $drivers[$d->id()] = $d->data()['full_name'] ?? $d->id();
}


// HANDLE UPDATE
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departure_time = $_POST['departure_time'] ?? '';
    $shuttle_id = $_POST['shuttle_id'] ?? '';
    $driver_id = $_POST['driver_id'] ?? null;
    $status = $_POST['status'] ?? 'published';

    if (!$departure_time || !$shuttle_id) {
        $error = "Departure time and shuttle are required.";
    } else {
        try {
            // 1. Recalculate ETAs based on the new departure time
            $baseTime = strtotime($schedule['date'] . ' ' . $departure_time);
            $newEtas = [];
            $stopsArr = $route['stop_ids'] ?? [];
            foreach ($stopsArr as $stopObj) {
                if (is_array($stopObj) && isset($stopObj['stop_id'], $stopObj['offset'])) {
                    $newEtas[$stopObj['stop_id']] = date('H:i', $baseTime + ($stopObj['offset'] * 60));
                } else {
                    $newEtas[is_string($stopObj) ? $stopObj : 'unknown'] = date('H:i', $baseTime);
                }
            }

            // 2. Save the new times and ETAs
            $ref->update([
                ['path' => 'departure_time', 'value' => $departure_time],
                ['path' => 'etas', 'value' => $newEtas],
                ['path' => 'shuttle_id', 'value' => $shuttle_id],
                ['path' => 'driver_id', 'value' => $driver_id ?: null],
                ['path' => 'status', 'value' => $status],
                ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
            ]);
            header('Location: schedules_management.php?msg=updated');
            exit();
        } catch (Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Schedule - CampusPulse';
$depth = '../../';
include $depth . 'layout/admin/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <h2 style="color:var(--primary-blue); margin-bottom: 20px;">Edit Schedule Details</h2>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">

        <div style="background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #eee;">
            <p style="margin-bottom:5px;"><strong>Route:</strong>
                <?= htmlspecialchars($route['route_name']) ?>
                (<?= htmlspecialchars($schedule['route_id']) ?>)</p>
            <p style="margin-bottom:5px;"><strong>Date:</strong>
                <?= date('d M Y', strtotime($schedule['date'])) ?></p>
            <p style="margin-bottom:0;"><strong>Direction:</strong>
                <?= ucfirst(str_replace('_', ' ', $route['direction'])) ?></p>
        </div>

        <div style="margin-bottom:15px;">
            <label style="font-weight:600;">Departure Time</label>
            <input type="time" name="departure_time" class="form-control"
                value="<?= htmlspecialchars($schedule['departure_time']) ?>" required>
        </div>

        <div style="margin-bottom:15px;">
            <label style="font-weight:600;">Assigned Shuttle</label>
            <select name="shuttle_id" class="form-control" required>
                <?php foreach ($shuttles as $sid): ?>
                    <option value="<?= $sid ?>" <?= ($schedule['shuttle_id'] ?? '') === $sid ? 'selected' : '' ?>>
                        <?= $sid ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom:15px;">
            <label style="font-weight:600;">Assigned Driver</label>
            <select name="driver_id" class="form-control">
                <option value="">-- Unassigned --</option>
                <?php foreach ($drivers as $did => $name): ?>
                    <option value="<?= $did ?>" <?= ($schedule['driver_id'] ?? '') === $did ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom:25px;">
            <label style="font-weight:600;">Status</label>
            <select name="status" class="form-control">
                <option value="published" <?= ($schedule['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published
                </option>
                <option value="cancelled" <?= ($schedule['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled
                </option>
            </select>
        </div>

        <div style="display:flex; justify-content:space-between;">
            <a href="schedules_management.php" class="btn" style="background:#eee; color:#333;">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>

    </form>
</div>


<?php include $depth . 'layout/admin/footer.php'; ?>