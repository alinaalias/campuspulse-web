<?php
session_start();
require_once 'config.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Fetch staff account
    $staffRef = $firestore->database()->collection('Staffs');
    $query = $staffRef->where('email', '=', $email);
    $snapshot = $query->documents();

    if ($snapshot->isEmpty()) {
        $error = "No account found with this email.";
    } else {
        foreach ($snapshot as $doc) {
            $user = $doc->data();

            // Verify password
            if (!password_verify($password, $user['password'])) {
                $error = "Invalid email or password.";
                break;
            }

            // Set Session Data
            $_SESSION['user_id'] = $doc->id();
            $_SESSION['role'] = $user['role'];

            $_SESSION['full_name'] = $user['full_name'] ?? 'Admin';
            $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';

            // strictly force offline parameter on fresh login to prevent ghost-online state
            if ($user['role'] === 'driver') {
                try {
                    $firestore->database()->collection('Staffs')->document($_SESSION['user_id'])->update([
                        ['path' => 'duty_status', 'value' => 'offline'],
                        ['path' => 'current_trip_id', 'value' => null]
                    ]);
                } catch (Exception $e) {}
            }

            // Redirect based on role
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - CampusPulse</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body class="login-body">

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
                <div style="position:relative;">
                    <input type="password" name="password" id="password" class="form-control" placeholder="••••••••"
                        required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Homepage
        </a>
    </div>

</body>

</html>