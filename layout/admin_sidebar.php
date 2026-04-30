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

$pendingDriverReviewsCount = 0;
try {
    $reviewQuery = $firestore->database()->collection('Staffs')
        ->where('role', '=', 'driver')
        ->where('status', '=', 'pending_review')
        ->documents();

    foreach ($reviewQuery as $doc) {
        $pendingDriverReviewsCount++;
    }
} catch (Exception $e) {
    $pendingDriverReviewsCount = 0;
}

// Fetch Pending Emergency Actions (Driver Breakdowns)
$pendingEmergenciesCount = 0;
try {
    $emergencyQuery = $firestore->database()->collection('Announcements')
        ->where('status', '=', 'active')
        ->where('tag', '=', '#Emergency')
        ->documents();

    foreach ($emergencyQuery as $doc) {
        $data = $doc->data();
        if (isset($data['location_lat']) && $data['location_lat'] !== 'N/A') {
            $pendingEmergenciesCount++;
        }
    }
} catch (Exception $e) {
    $pendingEmergenciesCount = 0;
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

    /* Safe Sidebar Scrolling - Doesn't break your existing layout */
    #sidebar {
        max-height: 100vh;
        overflow-y: auto;
        overflow-x: hidden;
    }

    /* Custom Scrollbar for Sidebar */
    #sidebar::-webkit-scrollbar {
        width: 5px;
    }

    #sidebar::-webkit-scrollbar-track {
        background: transparent;
    }

    #sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 10px;
    }

    #sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.4);
    }

    /* Category Headings */
    .sidebar-heading {
        color: #94a3b8;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 15px 20px 5px 20px;
        margin: 0;
        pointer-events: none;
    }

    /* Hide headings gracefully when your original admin.js collapses the sidebar */
    #sidebar.active .sidebar-heading {
        display: none;
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

        <li class="sidebar-heading">Overview</li>
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
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'main_analytics.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/analytics/main_analytics.php">
                <i class="fas fa-chart-line"></i> Analytics
            </a>
        </li>
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'live_operations.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/live_operations.php">
                <i class="fas fa-tower-broadcast"></i> Live Operations
            </a>
        </li>

        <li class="sidebar-heading">Dispatch</li>
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'bookings_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/booking_management/bookings_management.php">
                <i class="far fa-bookmark"></i> Bookings
            </a>
        </li>
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'schedules_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/schedule_management/schedules_management.php">
                <i class="far fa-calendar-alt"></i> Schedules
            </a>
        </li>

        <li class="sidebar-heading">Fleet & Staff</li>
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
                <?php if ($pendingDriverReviewsCount > 0): ?>
                    <span class="badge-count"
                        style="background-color: #f39c12; margin-left: 5px;"><?= $pendingDriverReviewsCount ?></span>
                <?php endif; ?>
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

        <li class="sidebar-heading">Users & Comms</li>
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'students_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/student_management/students_management.php">
                <i class="fas fa-user-graduate"></i> Students
            </a>
        </li>
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'announcements_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/announcement/announcements_management.php">
                <i class="fas fa-bullhorn"></i> <span>Announcements</span>
                <?php if ($pendingEmergenciesCount > 0): ?>
                    <span class="badge-count"
                        style="margin-left: 5px; animation: pulse 1.5s infinite;"><?= $pendingEmergenciesCount ?></span>
                <?php endif; ?>
            </a>
        </li>

    </ul>
</nav>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const sidebar = document.getElementById('sidebar');
        const content = document.getElementById('content');
        const sidebarToggle = document.getElementById('sidebarToggle');

        // 1. On page load, apply the saved state immediately
        if (localStorage.getItem('sidebarState') === 'collapsed') {
            if (sidebar) sidebar.classList.add('active');
            if (content) content.classList.add('active');
        } else if (localStorage.getItem('sidebarState') === 'expanded') {
            if (sidebar) sidebar.classList.remove('active');
            if (content) content.classList.remove('active');
        }

        // 2. Simply save the state when the user clicks the toggle.
        // We let your existing admin.js handle the actual animation/toggling.
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function () {
                // A 100ms delay ensures your existing admin.js finishes its toggle action first
                setTimeout(() => {
                    if (sidebar && sidebar.classList.contains('active')) {
                        localStorage.setItem('sidebarState', 'collapsed');
                    } else {
                        localStorage.setItem('sidebarState', 'expanded');
                    }
                }, 100);
            });
        }
    });
</script>