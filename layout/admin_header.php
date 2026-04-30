<?php
$path = isset($depth) ? $depth : '../';
$title = isset($pageTitle) ? $pageTitle : 'Admin Dashboard - CampusPulse';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>

    <link rel="icon" type="image/x-icon" href="<?= $path ?>img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $path ?>css/style.css">

    <style>
        .global-loader {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(4px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }

        .loader-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            color: var(--primary-blue, #0f172a);
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 1.2rem;
        }
    </style>
</head>

<body>
    <div id="global-loader" class="global-loader">
        <div class="loader-content">
            <i class="fas fa-circle-notch fa-spin" style="font-size:3rem;"></i>
            <span id="loader-text">Processing... Please wait.</span>
        </div>
    </div>

    <div class="wrapper">
        <?php include $path . 'layout/admin_sidebar.php'; ?>

        <div id="content">

            <nav class="top-navbar">

                <button type="button" id="sidebarToggle" class="btn-toggle">
                    <i class="fas fa-bars"></i>
                </button>

                <div style="flex: 1;"></div>

                <div class="user-profile">
                    <a href="<?= $path ?>admin/profile.php"
                        style="display:flex; align-items:center; gap:10px; text-decoration:none;">
                        <div style="text-align: right;">
                            <span style="display:block; font-weight:600; font-size:0.9rem; color:var(--primary-blue);">
                                <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?>
                            </span>
                            <span style="display:block; font-size:0.75rem; color:#888;">Logged In</span>
                        </div>

                        <?php
                        if (!empty($_SESSION['profile_pic'])) {
                            $photoSrc = $_SESSION['profile_pic'];
                        } else {
                            $photoSrc = $path . 'img/default-avatar.jpg';
                        }
                        ?>
                        <img src="<?= $photoSrc ?>" alt="Profile" class="user-avatar" style="object-fit: cover;">
                    </a>

                    <a href="<?= $path ?>logout.php" title="Logout" style="color: var(--danger); margin-left: 10px;">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>

            </nav>
            <div class="main-content">