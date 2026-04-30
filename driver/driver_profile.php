<?php
session_start();
require_once '../config.php'; // This loads $firestore and $bucket

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$driverId = $_SESSION['user_id'];
$driverRef = $firestore->database()->collection('Staffs')->document($driverId);
$driverSnap = $driverRef->snapshot();
$driver = $driverSnap->data();

$isForcedSetup = isset($_SESSION['force_profile_setup']) && $_SESSION['force_profile_setup'] === true;
$requiresComplianceUpdate = isset($_SESSION['requires_compliance_update']) && $_SESSION['requires_compliance_update'] === true;
$isPendingReview = ($driver['status'] ?? '') === 'pending_review';
$isSuspended = ($driver['status'] ?? '') === 'suspended';

$msg = "";
$error = "";

// --- Calculate Expiry Days for UI ---
$todayDate = new DateTime('today');
$licObj = !empty($driver['license_expiry']) ? new DateTime($driver['license_expiry']) : null;
$psvObj = !empty($driver['psv_expiry']) ? new DateTime($driver['psv_expiry']) : null;

$licDays = $licObj ? (int) $todayDate->diff($licObj)->format('%r%a') : null;
$psvDays = $psvObj ? (int) $todayDate->diff($psvObj)->format('%r%a') : null;

// --- Fetch Live Driver Rating ---
// Fetches the true average rating calculated and saved by driver_ratings.php
$rawRating = $driver['rating'] ?? 0;
$driverRating = ($rawRating > 0) ? number_format((float) $rawRating, 1) : 'New';
// -----------------------------------------

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates = [];

    // A. Handle Image Upload
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['profile_pic']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            try {
                $cloudPath = 'driver_profilepics/driver_' . $driverId . '.' . $ext;
                $fileStream = fopen($_FILES['profile_pic']['tmp_name'], 'r');
                $bucket->upload($fileStream, ['name' => $cloudPath]);
                $updates[] = ['path' => 'profile_pic', 'value' => $cloudPath];

                if ($isForcedSetup) {
                    unset($_SESSION['force_profile_setup']);
                    $shouldRedirect = true;
                }
            } catch (Exception $e) {
                $error = "Upload Failed: " . $e->getMessage();
            }
        } else {
            $error = "Only JPG and PNG files are allowed for profile picture.";
        }
    }

    // B. Handle License & PSV Docs Upload (Compliance)
    $uploadedDocs = false;
    if (isset($_FILES['license_pic']) && $_FILES['license_pic']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['license_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $cloudPath = 'driver_credentials/driver_' . $driverId . '_license.' . $ext;
            $bucket->upload(fopen($_FILES['license_pic']['tmp_name'], 'r'), ['name' => $cloudPath]);
            $updates[] = ['path' => 'license_pic', 'value' => $cloudPath];
            $uploadedDocs = true;
        }
    }
    if (isset($_FILES['psv_pic']) && $_FILES['psv_pic']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['psv_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $cloudPath = 'driver_credentials/driver_' . $driverId . '_psv.' . $ext;
            $bucket->upload(fopen($_FILES['psv_pic']['tmp_name'], 'r'), ['name' => $cloudPath]);
            $updates[] = ['path' => 'psv_pic', 'value' => $cloudPath];
            $uploadedDocs = true;
        }
    }

    // C. Handle Password Change (Instant)
    if (!empty($_POST['new_password'])) {
        $pwd = $_POST['new_password'];
        if (!preg_match('/(?=.*[A-Z])(?=.*[\W_]).{8,}/', $pwd)) {
            $error = "Password must be at least 8 characters long, contain at least one capital letter (A-Z), and one symbol (!@#$).";
        } else {
            $updates[] = ['path' => 'password', 'value' => password_hash($pwd, PASSWORD_DEFAULT)];
        }
    }

    // D. String field updates — SMART DETECTION
    if (isset($_POST['phone']) && $_POST['phone'] !== ($driver['phone_number'] ?? '')) {
        $updates[] = ['path' => 'phone_number', 'value' => $_POST['phone']];
    }
    if (isset($_POST['address']) && $_POST['address'] !== ($driver['home_address'] ?? '')) {
        $updates[] = ['path' => 'home_address', 'value' => $_POST['address']];
    }

    $hasComplianceTextChange = false;
    if (isset($_POST['license_number']) && $_POST['license_number'] !== ($driver['license_number'] ?? '')) {
        $updates[] = ['path' => 'license_number', 'value' => $_POST['license_number']];
        $hasComplianceTextChange = true;
    }
    if (isset($_POST['license_expiry']) && $_POST['license_expiry'] !== ($driver['license_expiry'] ?? '')) {
        $updates[] = ['path' => 'license_expiry', 'value' => $_POST['license_expiry']];
        $hasComplianceTextChange = true;
    }
    if (isset($_POST['psv_expiry']) && $_POST['psv_expiry'] !== ($driver['psv_expiry'] ?? '')) {
        $updates[] = ['path' => 'psv_expiry', 'value' => $_POST['psv_expiry']];
        $hasComplianceTextChange = true;
    }

    // STATUS DECISION
    $isComplianceUpdate = ($uploadedDocs || $hasComplianceTextChange);

    if (empty($updates) && empty($_FILES['profile_pic']['name'])) {
        $msg = "No changes were made.";
        $msgType = 'success';
    } else {
        if ($isComplianceUpdate) {
            if (($driver['status'] ?? '') === 'active') {
                $updates[] = ['path' => 'status', 'value' => 'pending_review'];
                $isPendingReview = true;
            }
            $updates[] = ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')];
        }

        // ENFORCE IMAGE UPLOAD
        if (empty($error)) {
            $currentPic = $driver['profile_pic'] ?? 'default.png';
            $imageBeingUpdated = false;
            foreach ($updates as $u) {
                if ($u['path'] === 'profile_pic')
                    $imageBeingUpdated = true;
            }
            if (($currentPic === 'default.png' || empty($currentPic)) && !$imageBeingUpdated) {
                $error = "Please upload a profile image to continue.";
            }
        }

        // Commit Changes
        if (empty($error) && !empty($updates)) {
            try {
                $driverRef->update($updates);

                if ($isComplianceUpdate) {
                    $msg = "License updates submitted. Admin review required to reactivate account.";
                    $msgType = 'warning';
                } else {
                    $msg = "Profile updated.";
                    $msgType = 'success';
                }

                if (isset($shouldRedirect) && !$requiresComplianceUpdate && !$isPendingReview && !$isSuspended) {
                    header("Location: driver_dashboard.php");
                    exit();
                }

                $driverSnap = $driverRef->snapshot();
                $driver = $driverSnap->data();

                $licObj = !empty($driver['license_expiry']) ? new DateTime($driver['license_expiry']) : null;
                $psvObj = !empty($driver['psv_expiry']) ? new DateTime($driver['psv_expiry']) : null;
                $licDays = $licObj ? (int) $todayDate->diff($licObj)->format('%r%a') : null;
                $psvDays = $psvObj ? (int) $todayDate->diff($psvObj)->format('%r%a') : null;

            } catch (Exception $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// 3. GENERATE SECURE IMAGE URLS
$picPath = $driver['profile_pic'] ?? 'default.png';
$displayUrl = "https://via.placeholder.com/150?text=No+Image";
if ($picPath !== 'default.png' && !empty($picPath)) {
    try {
        $displayUrl = $bucket->object($picPath)->signedUrl(new \DateTime('+30 minutes'));
    } catch (Exception $e) {
    }
}

$licenseUrl = "https://via.placeholder.com/400x200?text=No+License+Uploaded";
if (!empty($driver['license_pic'])) {
    try {
        $licenseUrl = $bucket->object($driver['license_pic'])->signedUrl(new \DateTime('+30 minutes'));
    } catch (Exception $e) {
    }
}

$psvUrl = "https://via.placeholder.com/400x200?text=No+PSV+Uploaded";
if (!empty($driver['psv_pic'])) {
    try {
        $psvUrl = $bucket->object($driver['psv_pic'])->signedUrl(new \DateTime('+30 minutes'));
    } catch (Exception $e) {
    }
}

$statusBadgeMap = [
    'active' => ['bg' => 'rgba(46, 204, 113, 0.15)', 'color' => '#27ae60', 'label' => 'ACTIVE'],
    'inactive' => ['bg' => 'rgba(149, 165, 166, 0.15)', 'color' => '#7f8c8d', 'label' => 'INACTIVE'],
    'suspended' => ['bg' => 'rgba(231, 76, 60, 0.15)', 'color' => '#c0392b', 'label' => 'SUSPENDED'],
    'pending_review' => ['bg' => 'rgba(243, 156, 18, 0.15)', 'color' => '#f39c12', 'label' => 'PENDING REVIEW']
];
$badge = $statusBadgeMap[$driver['status'] ?? 'inactive'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">

    <style>
        .driver-header {
            padding: 30px 20px 80px 20px;
            background: linear-gradient(135deg, var(--primary-blue), #0d3c78);
            color: white;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            position: relative;
            z-index: 10;
        }

        .profile-container {
            margin-top: -50px;
            padding: 0 20px 100px 20px;
            position: relative;
            z-index: 20;
        }

        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            position: relative;
        }

        .profile-upload-container {
            position: absolute;
            top: -50px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 100px;
        }

        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background: white;
        }

        .camera-btn {
            position: absolute;
            bottom: 0;
            right: -5px;
            background: var(--primary-blue);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            border: 2px solid white;
        }

        #fileInput {
            display: none;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .section-title {
            font-size: 0.95rem;
            color: var(--primary-blue);
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-row {
            display: flex;
            margin-bottom: 15px;
            align-items: center;
        }

        .info-icon {
            width: 35px;
            height: 35px;
            border-radius: 10px;
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }

        .info-content {
            flex: 1;
        }

        .info-label {
            font-size: 0.7rem;
            color: #888;
            text-transform: uppercase;
            font-weight: 600;
        }

        .info-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: #333;
        }

        .form-control-custom {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-top: 5px;
            transition: all 0.2s;
            font-family: inherit;
        }

        .form-control-custom:focus {
            border-color: var(--primary-blue);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .file-upload-box {
            border: 2px dashed #ddd;
            padding: 15px;
            text-align: center;
            border-radius: 12px;
            background: white;
            margin-top: 5px;
            position: relative;
        }

        .file-upload-box input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        /* Edit & Read Mode Styles */
        .readonly-mode .form-control-custom {
            background-color: transparent !important;
            border-color: transparent !important;
            padding: 0 !important;
            color: #333;
            font-weight: 500;
            pointer-events: none;
        }

        .readonly-mode .file-upload-box {
            display: none !important;
        }

        .edit-mode .current-doc-img {
            display: none !important;
        }

        .current-doc-img img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #eee;
            margin-top: 8px;
        }

        .input-expired {
            border-color: #e74c3c !important;
            background-color: #fdedec !important;
        }

        .input-warning {
            border-color: #f39c12 !important;
            background-color: #fef9e7 !important;
        }

        .compliance-section {
            background: #f8f9fa;
            border: 1px solid #e2e8f0;
        }

        /* --- Modal Overlay Styles --- */
        .hr-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            /* Dark transparent background */
            backdrop-filter: blur(3px);
            /* Nice blur effect */
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>

<body class="driver-body">

    <div id="pushPrompt"
        style="display:none; background: #34495e; color: white; padding: 15px; text-align: center; z-index: 9999; position: relative;">
        <span style="margin-right: 15px; font-size: 0.9rem;">Enable Push Notifications to receive real-time
            updates.</span>
        <button onclick="requestPushPermissions()"
            style="background:#2ecc71; color:white; border:none; padding:6px 12px; border-radius:6px; font-weight:600; cursor:pointer;">Enable</button>
    </div>

    <div class="driver-header">
        <!-- <div style="font-size: 0.8rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px;">Driver Access
        </div> -->
        <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700;">
            <?= $isForcedSetup ? 'Account Setup' : 'My Profile' ?>
        </h2>
    </div>

    <div class="profile-container">

        <form method="POST" enctype="multipart/form-data">

            <div class="profile-card" style="text-align:center; padding-top: 60px;">
                <div class="profile-upload-container" onclick="document.getElementById('fileInput').click()">
                    <img src="<?= $displayUrl ?>" class="profile-img" id="previewImg">
                    <div class="camera-btn"><i class="fas fa-camera"></i></div>
                </div>
                <input type="file" name="profile_pic" id="fileInput" accept="image/*" onchange="previewFile()">

                <h3 style="margin:0 0 5px; color:#2d3748; font-size:1.3rem;">
                    <?= htmlspecialchars($driver['full_name']) ?>
                </h3>
                <p style="color:#888; margin:0 0 5px; font-size:0.85rem;"><i class="fas fa-id-badge"></i>
                    <?= htmlspecialchars($driverId) ?></p>

                <div
                    style="font-size: 0.95rem; font-weight: 700; color: #333; margin-bottom: 15px; display:flex; justify-content:center; align-items:center; gap: 5px;">
                    <i class="fas fa-star" style="color: #f39c12;"></i> <?= $driverRating ?>
                </div>

                <div class="status-badge" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                    <?= $badge['label'] ?>
                </div>
            </div>

            <?php if ($requiresComplianceUpdate || $isPendingReview || $isSuspended): ?>
                <div id="accountLockedBanner"
                    style="background:#fff3cd; color:#856404; padding:15px; margin-bottom:20px; border-radius:12px; border:1px solid #ffeeba;">
                    <h4 style="margin:0 0 5px 0; font-size:1rem;"><i class="fas fa-exclamation-triangle"></i> Account Locked
                    </h4>
                    <p style="margin:0; font-size:0.85rem;">
                        <?php if ($isPendingReview): ?>
                            Your updated compliance documents are currently being reviewed by an administrator. You will regain
                            access to the dashboard once approved.
                        <?php elseif ($isSuspended): ?>
                            Your account has been suspended. Please check the reason below and upload required compliance
                            documents to regain access.
                        <?php else: ?>
                            Your compliance credentials have expired or are missing. You must upload valid documents to unlock
                            your account.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($driver['last_status_change_reason'])): ?>
                        <!--<div
                            style="margin-top:10px; background: rgba(255,255,255,0.5); padding: 8px; border-radius: 6px; font-size:0.8rem;">
                            <strong>Admin Note:</strong> <?= htmlspecialchars($driver['last_status_change_reason']) ?>
                        </div>-->
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div
                    style="background:#fbdada; color:#721c24; padding:12px; border-radius:12px; margin-bottom:15px; font-size:0.9rem;">
                    <i class="fas fa-ban"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($msg): ?>
                <?php $msgBg = (($msgType ?? 'success') === 'warning') ? '#fff3cd' : '#d4edda'; ?>
                <?php $msgColor = (($msgType ?? 'success') === 'warning') ? '#856404' : '#155724'; ?>
                <?php $msgIcon = (($msgType ?? 'success') === 'warning') ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>
                <div
                    style="background:<?= $msgBg ?>; color:<?= $msgColor ?>; padding:12px; border-radius:12px; margin-bottom:15px; font-size:0.9rem;">
                    <i class="fas <?= $msgIcon ?>"></i> <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <a href="driver_ratings.php" class="profile-card"
                style="display: flex; align-items: center; justify-content: space-between; text-decoration: none; color: inherit; padding: 15px 24px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div
                        style="width: 40px; height: 40px; background: #fff8e1; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #f39c12; font-size: 1.2rem;">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 1rem; color: #333;">My Ratings</div>
                        <div style="font-size: 0.8rem; color: #888;">View student reviews</div>
                    </div>
                </div>
                <i class="fas fa-chevron-right" style="color: #ccc;"></i>
            </a>

            <div class="profile-card">
                <div class="section-title"><i class="fas fa-user-circle"></i> Personal Info</div>

                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-envelope"></i></div>
                    <div class="info-content">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($driver['email'] ?? 'N/A') ?></div>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-icon" style="background:rgba(46, 204, 113, 0.1); color:#27ae60;"><i
                            class="fas fa-address-card"></i></div>
                    <div class="info-content">
                        <div class="info-label">IC Number</div>
                        <div class="info-value"><?= htmlspecialchars($driver['ic_number'] ?? 'N/A') ?></div>
                    </div>
                </div>

                <div class="info-row" style="margin-bottom:0;">
                    <div class="info-icon" style="background:rgba(243, 156, 18, 0.1); color:#f39c12;"><i
                            class="fas fa-van-shuttle"></i></div>
                    <div class="info-content">
                        <div class="info-label">Assigned Shuttle</div>
                        <div class="info-value"><?= htmlspecialchars($driver['assigned_shuttle_id'] ?? 'Unassigned') ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="profile-card readonly-mode" id="contactSection">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div class="section-title" style="margin:0;"><i class="fas fa-address-book"></i> Contact Info</div>
                    <button type="button" class="btn"
                        style="background:rgba(52,152,219,0.1); color:var(--primary-blue); padding:5px 12px; border-radius:8px; font-weight:600; border:none;"
                        onclick="toggleEdit('contactSection')"><i class="fas fa-edit"></i> Edit</button>
                </div>

                <div style="margin-bottom:15px;">
                    <label class="info-label">Phone Number</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($driver['phone_number'] ?? '') ?>"
                        class="form-control-custom" readonly>
                </div>

                <div style="margin-bottom:0;">
                    <label class="info-label">Home Address</label>
                    <textarea name="address" rows="2" class="form-control-custom"
                        readonly><?= htmlspecialchars($driver['home_address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="profile-card compliance-section readonly-mode" id="complianceSection">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div class="section-title" style="margin:0;"><i class="fas fa-id-card"></i> Driving Credentials
                    </div>
                    <button type="button" class="btn"
                        style="background:white; color:#333; border:1px solid #ddd; padding:5px 12px; border-radius:8px; font-weight:600;"
                        onclick="toggleEdit('complianceSection')"><i class="fas fa-edit"></i> Update</button>
                </div>

                <div id="complianceWarning"
                    style="display:none; background:#fff3cd; color:#856404; padding:10px; border-radius:8px; font-size:0.75rem; margin-bottom:15px; border:1px solid #ffeeba;">
                    <i class="fas fa-info-circle"></i> Updating fields in this section will temporarily lock your
                    account until an Admin reviews the changes.
                </div>

                <div style="margin-bottom:15px;">
                    <label class="info-label">License Number</label>
                    <input type="text" name="license_number"
                        value="<?= htmlspecialchars($driver['license_number'] ?? '') ?>" class="form-control-custom"
                        readonly>
                </div>

                <div style="display:flex; gap:15px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label class="info-label">License Expiry</label>
                        <?php $licClass = ($licDays !== null && $licDays < 0) ? 'input-expired' : (($licDays !== null && $licDays <= 30) ? 'input-warning' : ''); ?>
                        <input type="date" name="license_expiry"
                            value="<?= htmlspecialchars($driver['license_expiry'] ?? '') ?>"
                            class="form-control-custom <?= $licClass ?>" readonly>
                        <?php if ($licDays !== null && $licDays < 0): ?>
                            <div style="color:#e74c3c; font-size:0.7rem; font-weight:700; margin-top:4px;"><i
                                    class="fas fa-times-circle"></i> EXPIRED</div>
                        <?php elseif ($licDays !== null && $licDays <= 30): ?>
                            <div style="color:#f39c12; font-size:0.7rem; font-weight:700; margin-top:4px;"><i
                                    class="fas fa-exclamation-triangle"></i> Expires in <?= $licDays ?> days</div>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                        <label class="info-label">PSV Expiry</label>
                        <?php $psvClass = ($psvDays !== null && $psvDays < 0) ? 'input-expired' : (($psvDays !== null && $psvDays <= 30) ? 'input-warning' : ''); ?>
                        <input type="date" name="psv_expiry"
                            value="<?= htmlspecialchars($driver['psv_expiry'] ?? '') ?>"
                            class="form-control-custom <?= $psvClass ?>" readonly>
                        <?php if ($psvDays !== null && $psvDays < 0): ?>
                            <div style="color:#e74c3c; font-size:0.7rem; font-weight:700; margin-top:4px;"><i
                                    class="fas fa-times-circle"></i> EXPIRED</div>
                        <?php elseif ($psvDays !== null && $psvDays <= 30): ?>
                            <div style="color:#f39c12; font-size:0.7rem; font-weight:700; margin-top:4px;"><i
                                    class="fas fa-exclamation-triangle"></i> Expires in <?= $psvDays ?> days</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-bottom:15px;">
                    <label class="info-label">License Document</label>
                    <div class="current-doc-img">
                        <img src="<?= $licenseUrl ?>" alt="License">
                    </div>
                    <div class="file-upload-box" id="licBox">
                        <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem; color:#aaa; margin-bottom:5px;"></i>
                        <div style="font-size:0.85rem; color:#666;" id="licFileName">Tap to upload NEW License (JPG,
                            PNG, PDF)</div>
                        <input type="file" name="license_pic" onchange="updateFileName(this, 'licFileName', 'licBox')">
                    </div>
                </div>

                <div style="margin-bottom:0;">
                    <label class="info-label">PSV Document</label>
                    <div class="current-doc-img">
                        <img src="<?= $psvUrl ?>" alt="PSV Permit">
                    </div>
                    <div class="file-upload-box" id="psvBox">
                        <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem; color:#aaa; margin-bottom:5px;"></i>
                        <div style="font-size:0.85rem; color:#666;" id="psvFileName">Tap to upload NEW PSV (JPG, PNG,
                            PDF)</div>
                        <input type="file" name="psv_pic" onchange="updateFileName(this, 'psvFileName', 'psvBox')">
                    </div>
                </div>
            </div>

            <div class="profile-card readonly-mode" id="securitySection">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div class="section-title" style="margin:0;"><i class="fas fa-lock"></i> Security</div>
                    <button type="button" class="btn"
                        style="background:rgba(52,152,219,0.1); color:var(--primary-blue); padding:5px 12px; border-radius:8px; font-weight:600; border:none;"
                        onclick="toggleEdit('securitySection')"><i class="fas fa-edit"></i> Edit</button>
                </div>

                <div style="margin-bottom:5px;">
                    <label class="info-label">Update Password</label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="passwordInput" placeholder="••••••••"
                            class="form-control-custom" style="padding-right: 40px;" readonly>
                        <i class="fas fa-eye" id="toggleIcon" onclick="togglePassword()"
                            style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#999; cursor:pointer; display:none;"></i>
                    </div>
                    <small style="color:#888; font-size:0.7rem; display:block; margin-top:8px;">
                        Min 8 chars, 1 uppercase, 1 symbol (!@#$)
                    </small>
                </div>
            </div>

            <button id="submitBtn" type="submit" class="btn-massive"
                style="width:100%; padding: 18px; border-radius:14px; background:var(--primary-blue); color:white; font-size:1.1rem; font-weight:700; border:none; box-shadow:0 6px 15px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <i class="fas fa-save"></i> Submit All Changes
            </button>
        </form>

        <?php if (!$isForcedSetup && !$requiresComplianceUpdate): ?>
            <a href="#" onclick="showLogoutModal(event)"
                style="display:block; text-align:center; padding: 15px; border-radius:12px; background:#fff; color:#e74c3c; text-decoration:none; font-weight:600; border:1px solid #ffccd5;">
                <i class="fas fa-sign-out-alt"></i> Log Out
            </a>
            <div style="padding-bottom: 50px;"></div>
        <?php endif; ?>

        <div id="logoutModal" class="hr-modal-overlay" style="display:none; z-index: 9999;"
            onclick="if(event.target === this) closeLogoutModal()">
            <div class="hr-modal"
                style="max-width: 320px; text-align: center; padding: 30px 20px; background: white; border-radius: 20px; animation: slideUp 0.3s ease;">
                <div
                    style="width: 60px; height: 60px; background: #fdedec; color: #e74c3c; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 15px;">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h3 style="margin: 0 0 10px; color: #2d3748; font-size: 1.3rem;">Ready to leave?</h3>
                <p style="color: #718096; font-size: 0.9rem; margin-bottom: 25px;">Are you sure you want to log out of
                    your
                    account?</p>
                <div style="display: flex; gap: 10px;">
                    <button type="button"
                        style="flex: 1; background: #edf2f7; color: #4a5568; padding: 12px; border-radius: 12px; font-weight: 600; border: none; cursor: pointer;"
                        onclick="closeLogoutModal()">Cancel</button>
                    <a href="../logout.php"
                        style="flex: 1; background: #e74c3c; color: white; padding: 12px; border-radius: 12px; font-weight: 600; text-decoration: none; border: none; display: flex; align-items: center; justify-content: center;">Log
                        Out</a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$isForcedSetup && !$requiresComplianceUpdate): ?>
        <?php include 'driver_navbar.php'; ?>
    <?php endif; ?>

    <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
    <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-firestore.js"></script>
    <script>
        const firebaseConfig = {
            apiKey: "<?= MAPS_API_KEY ?? '' ?>",
            authDomain: "<?= FIREBASE_AUTH_DOMAIN ?? '' ?>",
            projectId: "<?= FIREBASE_PROJECT_ID ?? '' ?>",
            storageBucket: "<?= FIREBASE_STORAGE_BUCKET ?? '' ?>",
            messagingSenderId: "<?= FIREBASE_MESSAGING_SENDER_ID ?? '' ?>",
            appId: "<?= FIREBASE_APP_ID ?? '' ?>"
        };
        if (!firebase.apps.length) {
            firebase.initializeApp(firebaseConfig);
        }

        // --- HTTPS CHECK ---
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            console.warn('[CampusPulse] Push Notifications require HTTPS. Alerts may be blocked on this origin.');
        }

        function firePushNotification(title, body, url = 'driver_notifications.php') {
            if (Notification.permission !== 'granted') return;
            try {
                let notif = new Notification(title, { body: body, icon: '../img/favicon.ico' });
                notif.onclick = function () {
                    window.location.href = url; // Redirect when clicked
                };
                if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
            } catch (e) {
                console.warn('[CampusPulse] Notification error:', e);
            }
        }

        function requestPushPermissions() {
            if (!('Notification' in window)) return;
            Notification.requestPermission().then(permission => {
                if (permission === 'granted') {
                    document.getElementById('pushPrompt').style.display = 'none';
                    console.log('[CampusPulse] Push notifications enabled.');
                }
            });
        }

        document.addEventListener("DOMContentLoaded", function () {
            if ('Notification' in window && Notification.permission === 'default') {
                document.getElementById('pushPrompt').style.display = 'block';
            }

            const db = firebase.firestore();
            const driverId = "<?= $driverId ?>";
            const sessionStartTime = Date.now();
            let initialStaffLoad = true;

            // --- STAFFS DOC WATCHER: approve/reject real-time UI sync ---
            db.collection("Staffs").doc(driverId).onSnapshot((doc) => {
                if (!doc.exists) return;
                if (initialStaffLoad) { initialStaffLoad = false; return; }

                const data = doc.data();
                if (data.status === 'active') {
                    const banner = document.getElementById('accountLockedBanner');
                    if (banner) banner.style.display = 'none';
                    const btn = document.getElementById('submitBtn');
                    if (btn) { btn.innerHTML = '<i class="fas fa-save"></i> Submit Updates'; btn.style.background = 'var(--primary-blue)'; }

                    setTimeout(() => { window.location.href = 'driver_dashboard.php'; }, 2000);
                } else if (data.status === 'suspended' || data.status === 'inactive') {
                    location.reload();
                }
            });

            // --- NOTIFICATIONS COLLECTION WATCHER ---
            db.collection("Notifications").where("user_id", "==", driverId)
                .onSnapshot((snapshot) => {
                    snapshot.docChanges().forEach((change) => {
                        if (change.type !== 'added') return;
                        const data = change.doc.data();
                        const notifTime = new Date(data.created_at.replace(' ', 'T')).getTime();
                        // Only Push if it's a NEW notification created after the page loaded
                        if (notifTime > sessionStartTime) {
                            firePushNotification('CampusPulse Notification', data.message || data.title);
                        }
                    });
                });
        });

        // UI Toggles
        function toggleEdit(sectionId) {
            const section = document.getElementById(sectionId);
            const btn = section.querySelector('button');

            if (section.classList.contains('readonly-mode')) {
                section.classList.remove('readonly-mode');
                section.classList.add('edit-mode');
                btn.innerHTML = '<i class="fas fa-times"></i> Cancel';
                btn.style.color = '#e74c3c';

                // Show specific warnings or icons
                if (sectionId === 'complianceSection') {
                    document.getElementById('complianceWarning').style.display = 'block';
                }
                if (sectionId === 'securitySection') {
                    document.getElementById('passwordInput').placeholder = "Enter new password";
                    document.getElementById('toggleIcon').style.display = 'block';
                }

                const inputs = section.querySelectorAll('input, textarea');
                inputs.forEach(i => i.removeAttribute('readonly'));
            } else {
                section.classList.add('readonly-mode');
                section.classList.remove('edit-mode');
                btn.innerHTML = '<i class="fas fa-edit"></i> Edit';
                btn.style.color = sectionId === 'complianceSection' ? '#333' : 'var(--primary-blue)';

                if (sectionId === 'complianceSection') {
                    document.getElementById('complianceWarning').style.display = 'none';
                }
                if (sectionId === 'securitySection') {
                    document.getElementById('passwordInput').placeholder = "••••••••";
                    document.getElementById('toggleIcon').style.display = 'none';
                }

                const inputs = section.querySelectorAll('input, textarea');
                inputs.forEach(i => i.setAttribute('readonly', true));
            }
        }

        // File Previews
        function previewFile() {
            const preview = document.getElementById('previewImg');
            const file = document.getElementById('fileInput').files[0];
            const reader = new FileReader();
            reader.addEventListener("load", function () { preview.src = reader.result; }, false);
            if (file) { reader.readAsDataURL(file); }
        }

        function updateFileName(input, textId, boxId) {
            const el = document.getElementById(textId);
            const box = document.getElementById(boxId);
            if (input.files && input.files.length > 0) {
                el.innerText = input.files[0].name;
                box.style.borderColor = "var(--primary-blue)";
                box.style.background = "#eff8ff";
            }
        }

        function togglePassword() {
            const passwordInput = document.getElementById('passwordInput');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // --- Logout Modal Functions ---
        function showLogoutModal(e) {
            if (e) e.preventDefault(); // Stop the page from jumping to top
            document.getElementById('logoutModal').style.display = 'flex';
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }
    </script>
</body>

</html>