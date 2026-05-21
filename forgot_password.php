<?php
session_start();
require_once 'config.php';

$error = "";
$success_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        try {
            // Check if the email exists in the Staffs collection
            $staffRef = $firestore->collection('Staffs');
            $query = $staffRef->where('email', '=', $email);
            $snapshot = $query->documents();

            if (!$snapshot->isEmpty()) {
                // -> TODO: ACTUAL EMAIL LOGIC GOES HERE <-
                // 1. Generate a secure random token: $token = bin2hex(random_bytes(32));
                // 2. Save the token and an expiration timestamp to this user's Firestore document.
                // 3. Use an SMTP library (like PHPMailer) to email a link: 
                //    "https://yourdomain.com/reset_password.php?token=" . $token
            }

            // SECURITY BEST PRACTICE: 
            // Always show the same success message regardless of whether the email was found.
            // This prevents malicious users from guessing which emails are registered.
            $success_message = "If an account exists for that email, we have sent password reset instructions.";

        } catch (Exception $e) {
            $error = "A system error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CampusPulse</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border-left: 4px solid #28a745;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .instruction-text {
            color: #64748b;
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 25px;
            line-height: 1.5;
        }
    </style>
</head>

<body class="login-body">

    <div class="login-card">
        <div class="logo-area">
            <h2 class="login-title">Campus<span style="color:var(--accent-yellow)">Pulse</span></h2>
            <p class="login-subtitle">Password Recovery</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success_message) ?>
            </div>
            <a href="login_page.php" class="btn btn-primary btn-block"
                style="text-align: center; text-decoration: none; display: block;">
                Return to Login
            </a>
        <?php else: ?>
            <p class="instruction-text">
                Enter the email address associated with your staff or driver account, and we'll send you a secure link to
                reset your password.
            </p>

            <form method="POST" action="forgot_password.php">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="staff@campuspulse.my"
                        required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>

            <a href="login.php" class="back-link" style="margin-top: 20px; display: block;">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        <?php endif; ?>
    </div>

</body>

</html>