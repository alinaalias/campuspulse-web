<?php
$path = isset($depth) ? $depth : '../'; 
?>

<nav class="top-navbar" style="position: sticky; top: 0; padding: 0 50px; z-index: 1000; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; height: 80px;">
    <div class="brand-logo">
        <a href="index.php" style="text-decoration: none;">
            <h2 style="color:var(--primary-blue); font-weight:700; margin:0; font-size: 1.5rem;">
                Campus<span style="color:var(--accent-yellow)">Pulse</span>
            </h2>
        </a>
    </div>

    <div class="nav-links" style="display: flex; align-items: center; gap: 15px;">
        
        <?php if (!$isLoggedIn): ?>
            <a href="login.php" class="btn" style="color: #666; font-weight: 500; font-size: 0.9rem;">
                <i class="fas fa-lock" style="margin-right:5px; font-size:0.8rem;"></i> Staff Login
            </a>

            <a href="index.php#download-section" class="btn btn-primary" style="padding: 10px 25px; border-radius: 50px;">
                <i class="fas fa-mobile-alt"></i> Get the App
            </a>

        <?php else: ?>
            <?php if ($userRole === 'admin'): ?>
                <a href="admin/admin_dashboard.php" class="btn btn-primary">Admin Dashboard</a>
            <?php elseif ($userRole === 'driver'): ?>
                <a href="driver/driver_dashboard.php" class="btn btn-primary">Driver Dashboard</a>
            <?php else: ?>
                <a href="student/dashboard.php" class="btn btn-primary">My Dashboard</a>
            <?php endif; ?>
            
            <a href="logout.php" style="color:#dc3545; margin-left:10px; font-size: 1.2rem; padding: 10px;" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>

        <?php endif; ?>
    </div>
</nav>