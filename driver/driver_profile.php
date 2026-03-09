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

// Check "Gatekeeper" Mode (Force setup if flagged)
$isForcedSetup = isset($_SESSION['force_profile_setup']) && $_SESSION['force_profile_setup'] === true;

$msg = "";
$error = "";

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $updates = [];

    // A. Handle Image Upload (Unchanged)
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
            $error = "Only JPG and PNG files are allowed.";
        }
    }

    // B. Handle Password Change (UPDATED LOGIC)
    if (!empty($_POST['new_password'])) {
        $pwd = $_POST['new_password'];
        
        // REGEX: Min 8 chars, 1 Uppercase, 1 Symbol
        if (!preg_match('/(?=.*[A-Z])(?=.*[\W_]).{8,}/', $pwd)) {
            $error = "Password must be at least 8 characters long, contain at least one capital letter (A-Z), and one symbol (!@#$).";
        } else {
            $hashed = password_hash($pwd, PASSWORD_DEFAULT);
            $updates[] = ['path' => 'password', 'value' => $hashed];
        }
    }

    // C. Handle Phone Update
    if (!empty($_POST['phone'])) {
        $updates[] = ['path' => 'phone_number', 'value' => $_POST['phone']];
    }

    // --- NEW VALIDATION: ENFORCE IMAGE UPLOAD ---
    if (empty($error)) {
        $currentPic = $driver['profile_pic'] ?? 'default.png';
        $imageBeingUpdated = false;
        
        // Check if we are about to update the image
        foreach ($updates as $u) {
            if ($u['path'] === 'profile_pic') $imageBeingUpdated = true;
        }

        // If currently default/empty AND not uploading a new one -> Block
        if (($currentPic === 'default.png' || empty($currentPic)) && !$imageBeingUpdated) {
            $error = "Please upload profile image to continue";
        }
    }
    // -------------------------------------------

    // D. Commit Changes to Firestore
    if (empty($error) && !empty($updates)) {
        try {
            $driverRef->update($updates);
            $msg = "Profile updated successfully!";

            if (isset($shouldRedirect)) {
                header("Location: driver_dashboard.php");
                exit();
            }

            $driverSnap = $driverRef->snapshot();
            $driver = $driverSnap->data();
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// 3. GENERATE SECURE IMAGE URL (Unchanged)
$picPath = $driver['profile_pic'] ?? 'default.png';
$displayUrl = "https://via.placeholder.com/150?text=No+Image"; 

if ($picPath !== 'default.png' && !empty($picPath)) {
    try {
        $object = $bucket->object($picPath);
        if ($object->exists()) {
            $displayUrl = $object->signedUrl(new \DateTime('+30 minutes'));
        }
    } catch (Exception $e) {}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">

    <style>
        .profile-upload-container {
            position: relative; width: 120px; height: 120px; margin: 0 auto 20px;
        }
        .profile-img {
            width: 100%; height: 100%; object-fit: cover; border-radius: 50%;
            border: 4px solid white; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .camera-btn {
            position: absolute; bottom: 0; right: 0;
            background: var(--accent-yellow); color: var(--primary-blue);
            width: 35px; height: 35px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        #fileInput { display: none; }
    </style>
</head>
<body class="driver-body">

    <div class="driver-header" style="justify-content: center;">
        <h2 style="margin:0; font-size:1.2rem;">
            <?= $isForcedSetup ? 'Account Setup' : 'My Profile' ?>
        </h2>
    </div>

    <div class="driver-container">

        <?php if ($isForcedSetup): ?>
            <div style="background:#fff3cd; color:#856404; padding:15px; margin-bottom:20px; border-radius:12px; border:1px solid #ffeeba; text-align:center;">
                <i class="fas fa-exclamation-triangle" style="margin-bottom:5px; font-size:1.2rem;"></i><br>
                <strong>Action Required</strong><br>Please upload a profile picture to access the dashboard.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:12px; margin-bottom:15px; text-align:center;">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if ($msg): ?>
            <div style="background:#d4edda; color:#155724; padding:12px; border-radius:12px; margin-bottom:15px; text-align:center;">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <div class="driver-card" style="text-align:center;">
                <div class="profile-upload-container" onclick="document.getElementById('fileInput').click()">
                    <img src="<?= $displayUrl ?>" class="profile-img" id="previewImg">
                    <div class="camera-btn"><i class="fas fa-camera"></i></div>
                </div>
                <input type="file" name="profile_pic" id="fileInput" accept="image/*" onchange="previewFile()">
                <h3 style="margin:0; color:var(--primary-blue);"><?= htmlspecialchars($driver['full_name']) ?></h3>
                <p style="color:#777; margin:5px 0;">ID: <?= htmlspecialchars($driverId) ?></p>
            </div>

            <div class="driver-card">
                <h4 style="margin:0 0 15px 0; color:var(--primary-blue); border-bottom:1px solid #eee; padding-bottom:10px;">Details</h4>

                <div style="margin-bottom:15px;">
                    <label style="font-weight:600; font-size:0.85rem; color:#555;">Phone Number</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($driver['phone_number'] ?? '') ?>"
                        style="width:100%; padding:12px; border:1px solid #ddd; border-radius:10px; margin-top:5px; font-size:1rem;">
                </div>

                <div style="margin-bottom:25px;">
                    <label style="font-weight:600; font-size:0.85rem; color:#555;">New Password</label>
                    
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="passwordInput" placeholder="Set a new password"
                            style="width:100%; padding:12px; padding-right: 40px; border:1px solid #ddd; border-radius:10px; margin-top:5px;">
                        
                        <i class="fas fa-eye" id="toggleIcon" onclick="togglePassword()" 
                           style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#999; cursor:pointer;"></i>
                    </div>
                    
                    <small style="color:#666; font-size:0.75rem; margin-top:5px; display:block;">
                        Min 8 chars, 1 uppercase, 1 symbol (!@#$)
                    </small>
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>

        <?php if (!$isForcedSetup): ?>
            <a href="driver_ratings.php" class="driver-card" style="display: flex; align-items: center; justify-content: space-between; padding: 20px; text-decoration: none; color: inherit; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="width: 40px; height: 40px; background: #fff8e1; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--accent-yellow); font-size: 1.2rem;">
                        <i class="fas fa-star"></i>
                    </div>
                    <span style="font-weight: 600; font-size: 1rem;">My Ratings</span>
                </div>
                <i class="fas fa-chevron-right" style="color: #ccc;"></i>
            </a>

            <a href="../logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Log Out
            </a>
        <?php endif; ?>

        <a href="../index.php" class="btn" style="background:transparent; color:#666; padding-left:0;">
                    <i class="fas fa-arrow-left"></i> Back to public homepage
                </a>


    </div>

    <?php if (!$isForcedSetup): ?>
        <?php include 'driver_navbar.php'; ?>
    <?php endif; ?>

    <script>
        function previewFile() {
            const preview = document.getElementById('previewImg');
            const file = document.getElementById('fileInput').files[0];
            const reader = new FileReader();
            reader.addEventListener("load", function() { preview.src = reader.result; }, false);
            if (file) { reader.readAsDataURL(file); }
        }

        // Toggle Password Visibility
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
    </script>

</body>
</html>