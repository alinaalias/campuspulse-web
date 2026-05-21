<?php
session_start();
require_once 'config.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $staffRef = $firestore->collection('Staffs');
    $query = $staffRef->where('email', '=', $email);
    $snapshot = $query->documents();

    if ($snapshot->isEmpty()) {
        $error = "No account found with this email.";
    } else {
        foreach ($snapshot as $doc) {
            $user = $doc->data();

            if (!password_verify($password, $user['password'])) {
                $error = "The password you entered is incorrect.";
                break;
            }

            $_SESSION['user_id'] = $doc->id();
            $_SESSION['role'] = $user['role'];

            $_SESSION['full_name'] = $user['full_name'] ?? 'Admin';
            $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';

            if ($user['role'] === 'driver') {
                try {
                    $firestore->collection('Staffs')->document($_SESSION['user_id'])->update([
                        ['path' => 'duty_status', 'value' => 'offline'],
                        ['path' => 'current_trip_id', 'value' => null]
                    ]);
                } catch (Exception $e) {
                }
            }

            if ($user['role'] === 'admin') {
                header("Location: admin/admin_dashboard.php");
                exit;
            } elseif ($user['role'] === 'driver') {
                header("Location: driver/driver_dashboard.php");
                exit;
            } else {
                $error = "Unauthorized role.";
            }
        }
    }
}

$depth = '';
$pageTitle = 'Staff Login - CampusPulse';
$bodyClass = 'login-body';
$hideNav = true;

ob_start();
?>
<style>
    .password-wrapper {
        position: relative;
    }

    .toggle-password {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #888;
        font-size: 1.1rem;
        transition: color 0.2s;
    }

    .toggle-password:hover {
        color: var(--primary-blue);
    }

    .forgot-password {
        display: block;
        text-align: right;
        margin-top: 8px;
        font-size: 0.85rem;
        color: var(--primary-blue);
        text-decoration: none;
        font-weight: 500;
    }

    .forgot-password:hover {
        text-decoration: underline;
    }
</style>
<?php
$extraHead = ob_get_clean();
include $depth . 'layout/public/header.php';
?>

<div class="login-card">
    <div class="logo-area">
        <h2 class="login-title">Campus<span style="color:var(--accent-yellow)">Pulse</span></h2>
        <p class="login-subtitle">Staff & Driver Access Portal</p>
    </div>

    <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" name="email" id="email" class="form-control" placeholder="staff@campuspulse.my"
                required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input type="password" name="password" id="password" class="form-control" placeholder="••••••••"
                    required style="padding-right: 40px;">
                <i class="fas fa-eye toggle-password" id="togglePasswordIcon" onclick="togglePassword()"></i>
            </div>
            <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            <i class="fas fa-sign-in-alt"></i> Login
        </button>
    </form>

    <a href="index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Homepage
    </a>
</div>

<script>
    function togglePassword() {
        const pwdInput = document.getElementById('password');
        const icon = document.getElementById('togglePasswordIcon');
        if (pwdInput.type === 'password') {
            pwdInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            pwdInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>

<?php
$hideFooter = true;
include $depth . 'layout/public/footer.php';
?>