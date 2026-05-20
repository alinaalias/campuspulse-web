<?php
// Fallback variables to prevent undefined errors
$path = isset($depth) ? $depth : '';
$title = isset($pageTitle) ? $pageTitle : 'CampusPulse – Smart Shuttle Service';
$bodyCssClass = isset($bodyClass) ? $bodyClass : '';
$hideNavbar = isset($hideNav) ? $hideNav : false;

// Determine Auth Status if not already defined
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>

    <link rel="icon" type="image/x-icon" href="<?= $path ?>img/campulse_favicon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@700;800;900&family=Poppins:wght@300;400;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="<?= $path ?>css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <?php if (isset($extraHead))
        echo $extraHead; ?>
</head>

<body class="<?= htmlspecialchars($bodyCssClass) ?>">

    <?php if (!$hideNavbar): ?>
        <nav class="top-navbar"
            style="position: sticky; top: 0; padding: 0 50px; z-index: 1000; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; height: 80px;">
            <div class="brand-logo">
                <a href="<?= $path ?>index.php" style="text-decoration: none; display: flex; align-items: center;">
                    <img src="<?= $path ?>img/CampusPulse Logo.png" alt="CampusPulse Logo"
                        style="max-height: 200px; width: auto; object-fit: contain;">
                </a>
            </div>

            <div class="nav-links" style="display: flex; align-items: center; gap: 15px;">
                <?php if (!$isLoggedIn): ?>
                    <a href="<?= $path ?>login.php" class="btn" style="color: #666; font-weight: 500; font-size: 0.9rem;">
                        <i class="fas fa-lock" style="margin-right:5px; font-size:0.8rem;"></i> Staff Login
                    </a>
                    <a href="<?= $path ?>index.php#download-section" class="btn btn-primary"
                        style="padding: 10px 25px; border-radius: 50px;">
                        <i class="fas fa-mobile-alt"></i> Get the App
                    </a>
                <?php else: ?>
                    <?php if ($userRole === 'admin'): ?>
                        <a href="<?= $path ?>admin/admin_dashboard.php" class="btn btn-primary">Admin Dashboard</a>
                    <?php elseif ($userRole === 'driver'): ?>
                        <a href="<?= $path ?>driver/driver_dashboard.php" class="btn btn-primary">Driver Dashboard</a>
                    <?php endif; ?>

                    <a href="<?= $path ?>logout.php" style="color:#dc3545; margin-left:10px; font-size: 1.2rem; padding: 10px;"
                        title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>