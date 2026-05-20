<?php
// Get the current file name (e.g., 'driver_dashboard.php')
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="bottom-nav">
   <a href="driver_dashboard.php" class="nav-item <?= $current_page == 'driver_dashboard.php' ? 'active' : '' ?>">
      <i class="fas fa-home"></i> Home
   </a>

   <a href="driver_schedule.php" class="nav-item <?= $current_page == 'driver_schedule.php' ? 'active' : '' ?>">
      <i class="far fa-calendar-alt"></i> Schedule
   </a>

   <a href="driver_profile.php" class="nav-item <?= $current_page == 'driver_profile.php' ? 'active' : '' ?>">
      <i class="fas fa-user"></i> Profile
   </a>
</div>