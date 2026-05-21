<?php
session_start();
require_once 'config.php';

$hasActiveTrip = false;
$shuttleId = null;

// 1. GATEKEEPER: Fetch Driver State Before Logout
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'driver') {
    try {
        $driverSnap = $firestore->collection('Staffs')->document($_SESSION['user_id'])->snapshot();
        if ($driverSnap->exists()) {
            $driverData = $driverSnap->data();
            $shuttleId = $driverData['assigned_shuttle_id'] ?? null;

            // Check if they are currently on a trip
            if (!empty($driverData['current_trip_id'])) {
                $hasActiveTrip = true;
            }
        }
    } catch (Exception $e) {
    }
}

// 2. PROCESS LOGOUT (Only if confirmed AND no active trips)
if (isset($_GET['confirm']) && $_GET['confirm'] === '1' && !$hasActiveTrip) {
    if (isset($_SESSION['user_id'])) {
        try {
            // Update Driver to Offline
            $firestore->collection('Staffs')->document($_SESSION['user_id'])->update([
                ['path' => 'duty_status', 'value' => 'offline'],
                ['path' => 'current_trip_id', 'value' => null]
            ]);

            // LOOPHOLE FIX: Force the Assigned Shuttle to Offline
            if (!empty($shuttleId)) {
                $firestore->collection('Shuttles')->document($shuttleId)->update([
                    ['path' => 'is_online', 'value' => false]
                ]);
            }
        } catch (Exception $e) {
            // Failsafe catch
        }
    }

    session_unset();
    session_destroy();

    header('Location: login.php');
    exit();
}

$depth = '';
$pageTitle = 'Logout - CampusPulse';
$hideNav = true;

ob_start();
?>
<style>
    body {
        background: #f4f6f9;
        font-family: 'Poppins', sans-serif;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100vh;
        margin: 0;
    }

    .logout-modal {
        background: white;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        text-align: center;
        max-width: 400px;
        width: 90%;
    }

    .logout-icon {
        font-size: 3.5rem;
        margin-bottom: 20px;
    }

    .icon-danger {
        color: #e74c3c;
    }

    .icon-warning {
        color: #f39c12;
    }

    .logout-modal h2 {
        margin: 0 0 10px;
        color: #2d3748;
        font-size: 1.8rem;
    }

    .logout-modal p {
        color: #718096;
        margin-bottom: 30px;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .btn-group {
        display: flex;
        gap: 15px;
        justify-content: center;
    }

    .btn {
        flex: 1;
        padding: 14px;
        border-radius: 12px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        border: none;
        font-size: 1rem;
        transition: 0.2s;
        font-family: inherit;
    }

    .btn-cancel {
        background: #edf2f7;
        color: #4a5568;
    }

    .btn-cancel:hover {
        background: #e2e8f0;
    }

    .btn-confirm {
        background: #e74c3c;
        color: white;
    }

    .btn-confirm:hover {
        background: #c0392b;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(231, 76, 60, 0.2);
    }

    .btn-return {
        background: #0A3060;
        /* CampusPulse Primary Blue */
        color: white;
    }

    .btn-return:hover {
        background: #004080;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(10, 48, 96, 0.2);
    }
</style>
<?php
$extraHead = ob_get_clean();
include $depth . 'layout/public/header.php';
?>

<div class="logout-modal">
    <?php if ($hasActiveTrip): ?>
        <i class="fas fa-exclamation-triangle logout-icon icon-warning"></i>
        <h2>Active Trip Detected</h2>
        <p>You cannot sign out while you are servicing an active trip. Please complete or cancel the trip first to ensure
            student safety.</p>
        <div class="btn-group">
            <button onclick="window.history.back()" class="btn btn-return">Return to Dashboard</button>
        </div>
    <?php else: ?>
        <i class="fas fa-sign-out-alt logout-icon icon-danger"></i>
        <h2>Sign Out?</h2>
        <p>Are you sure you want to securely end your current session?</p>
        <div class="btn-group">
            <button onclick="window.history.back()" class="btn btn-cancel">Cancel</button>
            <a href="?confirm=1" class="btn btn-confirm">Yes, Sign Out</a>
        </div>
    <?php endif; ?>
</div>

<?php
$hideFooter = true;
include $depth . 'layout/public/footer.php';
?>