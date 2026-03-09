<?php
session_start();
require_once '../../config.php';

// Admin-only access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$error = '';
$success = '';

/* Fetch active shuttles */
$shuttles = [];
foreach ($firestore->database()->collection('Shuttles')->where('status', '=', 'active')->documents() as $s) {
    $shuttles[] = $s->id();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone_number']);
    $password = trim($_POST['password']);
    $assigned_shuttle_id = $_POST['assigned_shuttle_id'] ?: null;

    if (!$full_name || !$phone || !$password) {
        $error = "All fields except Assigned Shuttle are required.";
    } else {
        // --- 1. AUTO-GENERATE EMAIL BASE ---
        // Clean the name: lowercase, remove non-alphanumeric chars (keep spaces)
        $cleanStr = strtolower(preg_replace('/[^a-zA-Z0-9 ]/', '', $full_name));
        $parts = array_values(array_filter(explode(' ', $cleanStr))); // Reset keys
        
        $emailBase = "driver"; // Fallback
        
        if (count($parts) >= 2) {
            // Format: first.last
            $emailBase = $parts[0] . '.' . end($parts);
        } elseif (count($parts) == 1) {
            // Format: first
            $emailBase = $parts[0];
        }

        $domain = '@d.campuspulse.com';
        $finalEmail = $emailBase . $domain;

        // --- 2. DUPLICATE CHECK & NUMBERING ---
        // We assume the email is unique. If not, we append a number.
        $counter = 0;
        $isUnique = false;

        while (!$isUnique) {
            // Check Firestore for this specific email
            $query = $firestore->database()->collection('Staffs')->where('email', '=', $finalEmail)->documents();
            
            // If the iterator is empty, it means no documents found -> Unique!
            if ($query->isEmpty()) {
                $isUnique = true;
            } else {
                // Not unique, increment counter and try again
                $counter++;
                $finalEmail = $emailBase . $counter . $domain;
            }
        }
        // ------------------------------------

        $driverId = generateCustomId('drivers', 'DRV', $firestore);
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $firestore->database()->collection('Staffs')->document($driverId)->set([
                'full_name' => $full_name,
                'email' => $finalEmail, // Uses the final unique email
                'phone_number' => $phone,
                'password' => $password_hash,
                'profile_pic' => null,
                'role' => 'driver',
                'status' => 'active',
                'assigned_shuttle_id' => $assigned_shuttle_id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $success = "Driver added successfully! ID: $driverId (Email: $finalEmail)";
        } catch (Exception $e) {
            $error = "Failed to add driver: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Driver - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style.css">
</head>
<body>

<div class="wrapper">
    <?php $depth = '../../'; ?>
    <?php include '../../layout/sidebar.php'; ?>

    <div id="content">
        <?php include '../../layout/header.php'; ?>

        <div class="main-content">
            
            <div class="card" style="max-width: 600px; margin: 0 auto;">
                <h2 style="color:var(--primary-blue); margin-bottom: 20px;">Register New Driver</h2>

                <?php if ($error): ?>
                    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div style="background:#d4edda; color:#155724; padding:12px; border-radius:6px; margin-bottom:20px; border-left:4px solid var(--success);">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" required placeholder="e.g. Ahmad bin Abu ">
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Email Address (Auto-generated)</label>
                        <input type="email" id="email" name="email" class="form-control" readonly 
                               style="background-color: #e9ecef; cursor: not-allowed; color: #666;"
                               placeholder="@d.campuspulse.com">
                        <small style="color:#888; font-size: 0.8rem;">* If this email exists, a number (e.g. 1, 2) will be added automatically.</small>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Phone Number</label>
                        <input type="text" name="phone_number" class="form-control" required>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="font-weight:600;">Temporary Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div style="margin-bottom:25px;">
                        <label style="font-weight:600;">Assign Shuttle (Optional)</label>
                        <select name="assigned_shuttle_id" class="form-control">
                            <option value="">-- None --</option>
                            <?php foreach ($shuttles as $sid): ?>
                                <option value="<?= htmlspecialchars($sid) ?>">
                                    <?= htmlspecialchars($sid) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:space-between;">
                        <a href="drivers_management.php" class="btn" style="background:#eee; color:#333;">Back to List</a>
                        <button type="submit" class="btn btn-primary">Add Driver</button>
                    </div>

                </form>
            </div>

        </div>
    </div>
</div>

<script>
    document.getElementById('full_name').addEventListener('input', function(e) {
        const fullName = e.target.value.toLowerCase();
        
        // Remove special characters, keep spaces
        const cleanName = fullName.replace(/[^a-z0-9 ]/g, '');
        
        // Split into parts
        const parts = cleanName.split(' ').filter(part => part.length > 0);
        
        let emailPrefix = "";
        
        if (parts.length >= 2) {
            // Combine First Name . Last Name
            emailPrefix = parts[0] + '.' + parts[parts.length - 1];
        } else if (parts.length === 1) {
            // Just First Name
            emailPrefix = parts[0];
        }
        
        const domain = "@d.campuspulse.com";
        document.getElementById('email').value = emailPrefix ? (emailPrefix + domain) : "";
    });
</script>

</body>
</html>