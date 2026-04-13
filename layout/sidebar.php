<?php
// Fetch pending driver count for the badge
$pendingCount = 0;
try {
    $pendingQuery = $firestore->database()->collection('DriverApplications')
        ->where('status', '=', 'pending')
        ->documents();

    // Count how many documents are in the snapshot
    foreach ($pendingQuery as $doc) {
        $pendingCount++;
    }
} catch (Exception $e) {
    $pendingCount = 0; // Fallback to 0 if error
}
?>

<style>
    .badge-count {
        background-color: #dc3545;
        /* Red alert color */
        color: white;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 10px;
        min-width: 20px;
        text-align: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }
</style>

<nav id="sidebar">
    <div class="sidebar-header">
        <h3 style="color:white; font-weight:700; margin:0; letter-spacing:1px;">
            Campus<span style="color:var(--accent-yellow)">Pulse</span>
        </h3>
    </div>

    <?php $path = isset($depth) ? $depth : '../'; ?>

    <ul class="list-unstyled components">
        <li>
            <a href="<?= $path ?>index.php" style="background: rgba(255,255,255,0.1);">
                <i class="fas fa-globe"></i> Home
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/admin_dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'live_operations.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/live_operations.php">
                <i class="fas fa-tower-broadcast"></i> Live Operations
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'admin_review_drivers.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/admin_review_drivers.php">
                <i class="fas fa-id-card"></i> Review Drivers Applications
                <?php if ($pendingCount > 0): ?>
                    <span class="badge-count"><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'routes_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/route_management/routes_management.php">
                <i class="fas fa-map-signs"></i> Routes
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'shuttles_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/shuttle_management/shuttles_management.php">
                <i class="fas fa-van-shuttle"></i> Shuttles
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'drivers_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/driver_management/drivers_management.php">
                <i class="fas fa-user-tie"></i> Drivers
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'schedules_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/schedule_management/schedules_management.php">
                <i class="far fa-calendar-alt"></i> Schedules
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'bookings_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/booking_management/bookings_management.php">
                <i class="far fa-bookmark"></i> Bookings
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'students_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/student_management/students_management.php">
                <i class="fas fa-user-graduate"></i> Students
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'announcements_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/announcement/announcements_management.php">
                <i class="fas fa-bullhorn"></i> Announcements
            </a>
        </li>



    </ul>
</nav>