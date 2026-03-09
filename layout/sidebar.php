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
        
        <li class="<?= basename($_SERVER['PHP_SELF']) == 'routes_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/route_management/routes_management.php">
                <i class="fas fa-map-signs"></i> Routes
            </a>
        </li>

        <li class="<?= basename($_SERVER['PHP_SELF']) == 'shuttles_management.php' ? 'active' : '' ?>">
            <a href="<?= $path ?>admin/shuttle_management/shuttles_management.php">
                <i class="fas fa-bus"></i> Shuttles
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