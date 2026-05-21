<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$adminId = $_SESSION['user_id'];
$docRef = $firestore->collection('Staffs')->document($adminId);
$snapshot = $docRef->snapshot();

if (!$snapshot->exists())
    die("Error: Admin profile not found.");

$admin = $snapshot->data();
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name']);
    $phone = trim($_POST['phone_number']);
    $password = trim($_POST['password']);

    // Base Updates
    $updateData = [
        ['path' => 'full_name', 'value' => $fullName],
        ['path' => 'phone_number', 'value' => $phone],
        ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
    ];

    // 1. Password Update Logic
    if (!empty($password)) {
        if (!preg_match('/(?=.*[A-Z])(?=.*[\W_]).{8,}/', $password)) {
            $error = "Password must be at least 8 characters long, contain at least one capital letter (A-Z), and one symbol (!@#$).";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $updateData[] = ['path' => 'password', 'value' => $hashedPassword];
        }
    }

    // 2. Firebase Storage Upload (Unchanged)
    if (empty($error) && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($ext, $allowed)) {
            try {
                $oldUrl = $admin['profile_pic'] ?? '';
                if (!empty($oldUrl) && strpos($oldUrl, $bucketName) !== false) {
                    // Extract the object name from the public URL
                    // Example: https://storage.googleapis.com/bucket-name/admin_profiles/filename.png
                    $pathParts = explode($bucketName . '/', $oldUrl);
                    if (isset($pathParts[1])) {
                        $oldObjectPath = $pathParts[1];
                        $oldObject = $bucket->object($oldObjectPath);
                        if ($oldObject->exists()) {
                            $oldObject->delete();
                        }
                    }
                }

                $cloudPath = 'admin_profiles/' . $adminId . '_' . time() . '.' . $ext;
                $fileStream = fopen($file['tmp_name'], 'r');
                $object = $bucket->upload($fileStream, ['name' => $cloudPath]);
                $object->update(['acl' => []], ['predefinedAcl' => 'PUBLICREAD']);

                $publicUrl = "https://storage.googleapis.com/{$bucketName}/{$cloudPath}";
                $updateData[] = ['path' => 'profile_pic', 'value' => $publicUrl];
                $_SESSION['user_photo'] = $publicUrl;

            } catch (Exception $e) {
                $error = "Firebase Upload Error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid file type. JPG, PNG only.";
        }
    }

    // 3. Save Changes
    if (!$error) {
        try {
            $docRef->update($updateData);
            $_SESSION['full_name'] = $fullName;
            $success = "Profile updated successfully!";
            $admin = $docRef->snapshot()->data();
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Profile Settings - CampusPulse';
$depth = '../';
include $depth . 'layout/admin/header.php';
?>

<div class="card" style="max-width: 850px; margin: 0 auto;">
    <h2 style="color:var(--primary-blue); margin-bottom: 25px; border-bottom:1px solid #eee; padding-bottom:10px;">
        Profile Settings
    </h2>

    <?php if ($error): ?>
        <div class="alert-error"
            style="background:#f8d7da; color:#721c24; padding:15px; border-radius:6px; margin-bottom:20px; border:1px solid #f5c6cb;">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div
            style="background:#d4edda; color:#155724; padding:12px; border-radius:6px; margin-bottom:20px; text-align:center; border:1px solid #c3e6cb;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="display:flex; gap:40px; flex-wrap:wrap;">

        <div style="flex:1; text-align:center; min-width:250px; border-right:1px solid #f0f0f0; padding-right:20px;">
            <div style="position:relative; display:inline-block; margin-bottom:15px;">
                <img src="<?= htmlspecialchars($admin['profile_pic'] ?? '../img/default-avatar.jpg') ?>" id="previewImg"
                    style="width: 180px; height: 180px; border-radius: 50%; object-fit: cover; border: 4px solid var(--accent-yellow); box-shadow: 0 5px 15px rgba(0,0,0,0.1);">

                <label for="photoInput"
                    style="position:absolute; bottom:10px; right:10px; background:var(--primary-blue); color:white; width:45px; height:45px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.2); transition:0.3s;">
                    <i class="fas fa-camera"></i>
                </label>
                <input type="file" name="photo" id="photoInput" style="display:none;" accept="image/*"
                    onchange="previewFile(this)">
            </div>
            <h4 style="margin:0; color:var(--primary-blue);">
                <?= htmlspecialchars($admin['full_name']) ?>
            </h4>
            <p style="color:#777; font-size:0.9rem;">Administrator</p>
        </div>

        <div style="flex:2; min-width:300px;">

            <div style="margin-bottom:15px;">
                <label style="font-weight:600;">Full Name</label>
                <input type="text" name="full_name" class="form-control"
                    value="<?= htmlspecialchars($admin['full_name']) ?>" required>
            </div>

            <div style="margin-bottom:15px;">
                <label style="font-weight:600;">Email Address</label>
                <div style="position:relative;">
                    <input type="email" class="form-control" value="<?= htmlspecialchars($admin['email']) ?>" disabled
                        style="background:#f4f4f4; color:#666; padding-left:40px;">
                    <i class="fas fa-envelope"
                        style="position:absolute; left:15px; top:50%; transform:translateY(-50%); color:#999;"></i>
                </div>
                <small style="color:#999;">Email cannot be changed directly.</small>
            </div>

            <div style="margin-bottom:15px;">
                <label style="font-weight:600;">Phone Number</label>
                <input type="text" name="phone_number" class="form-control"
                    value="<?= htmlspecialchars($admin['phone_number']) ?>">
            </div>

            <div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">
                <h4 style="margin-bottom:15px; color:var(--primary-blue);">Security</h4>

                <label style="font-weight:600;">New Password</label>

                <div style="position:relative;">
                    <input type="password" name="password" id="passwordInput" class="form-control"
                        placeholder="Leave blank to keep current password" style="padding-right: 40px;">
                    <i class="fas fa-eye" id="toggleIcon" onclick="togglePassword()"
                        style="position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; color:#999; z-index:10;">
                    </i>
                </div>

                <small style="color:#666; display:block; margin-top:5px; font-size:0.85rem;">
                    <i class="fas fa-info-circle"></i> Must be at least 8 characters, include a capital
                    letter (A-Z) and a symbol (!@#$).
                </small>
            </div>

            <div style="text-align:right; margin-top:25px;">
                <button type="submit" class="btn btn-primary" style="padding:12px 30px; font-size:1rem;">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>

        </div>
    </form>
</div>

</div>
</div>
</div>

<script>
    // Image Preview
    function previewFile(input) {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function () {
                document.getElementById('previewImg').src = reader.result;
            }
            reader.readAsDataURL(file);
        }
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

<?php include $depth . 'layout/admin/footer.php'; ?>