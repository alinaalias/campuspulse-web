<?php
// layout/driver/header.php
$path = isset($depth) ? $depth : '../';
$title = isset($pageTitle) ? $pageTitle : 'Driver Portal - CampusPulse';
$cssVersion = time();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($title) ?></title>

    <link rel="icon" type="image/x-icon" href="<?= $path ?>img/campulse_favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $path ?>css/style.css?v=<?= $cssVersion ?>">
    <link rel="stylesheet" href="<?= $path ?>css/driver/style.css?v=<?= $cssVersion ?>">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php if (isset($extraHead))
        echo $extraHead; ?>
</head>

<body class="driver-body">
    <div id="pushPrompt"
        style="display:none; background: #34495e; color: white; padding: 15px; text-align: center; z-index: 9999; position: relative;">
        <span style="margin-right: 15px; font-size: 0.9rem;">Enable Push Notifications to receive real-time
            updates.</span>
        <button onclick="requestPushPermissions()"
            style="background:#2ecc71; color:white; border:none; padding:6px 12px; border-radius:6px; font-weight:600; cursor:pointer;">Enable</button>
    </div>