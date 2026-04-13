<?php
require_once 'config.php'; 
session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $_SESSION['role'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Become a Driver – CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: #f8f9fa; 
            margin: 0;
            padding: 0;
        }

        /* Page Banner Styles */
        .page-banner {
            background: linear-gradient(135deg, #003366 0%, #004080 100%);
            color: white;
            padding: 60px 20px;
            text-align: center;
            margin-bottom: 40px;
        }
        .page-banner h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .page-banner p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Layout Container */
        .application-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px 60px 20px;
        }

        /* Form Styles */
        .driver-application-form .driver-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid #eaeaea;
        }

        .driver-application-form h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #003366;
            font-size: 1.3rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }

        .driver-application-form .section-desc {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 20px;
            background: #e3f2fd;
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 4px solid #003366;
        }

        .driver-application-form .form-group {
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
        }

        .driver-application-form .form-group label {
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
            font-size: 0.95rem;
        }

        .driver-application-form .form-group input[type="text"],
        .driver-application-form .form-group input[type="email"],
        .driver-application-form .form-group input[type="tel"],
        .driver-application-form .form-group input[type="date"],
        .driver-application-form .form-group input[type="number"],
        .driver-application-form .form-group select,
        .driver-application-form .form-group textarea {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #fcfcfc;
        }

        .driver-application-form .form-group input:focus,
        .driver-application-form .form-group select:focus,
        .driver-application-form .form-group textarea:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
            background-color: #fff;
        }

        .driver-application-form .checkbox-group {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            background: #fcfcfc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #eee;
        }

        .driver-application-form .checkbox-group input[type="checkbox"] {
            margin-top: 3px;
            margin-right: 15px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .driver-application-form .checkbox-group label {
            color: #444;
            line-height: 1.5;
            font-size: 0.95rem;
            cursor: pointer;
            margin: 0;
        }

        .driver-application-form .form-actions {
            text-align: right;
            margin-top: 30px;
        }

        .btn-save {
            background-color: #003366;
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s;
            font-family: inherit;
        }

        .btn-save:hover {
            background-color: #004080;
            transform: translateY(-2px);
        }

        /* Success/Error Alerts */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

<?php include 'layout/public_header.php'; ?>

<div class="page-banner">
    <h1>Join the CampusPulse Fleet</h1>
    <p>Help fellow students commute safely while earning. Apply to become an official UniKL shuttle driver today.</p>
</div>

<div class="application-container">
    
    <?php
    // Display success or error messages if redirected back from submit_application.php
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
    ?>

    <form action="submit_application.php" method="POST" enctype="multipart/form-data" class="driver-application-form">
        
        <div class="driver-card">
            <h3><i class="fas fa-user"></i> Personal Details</h3>
            <div class="form-group">
                <label for="full_name">Full Name (as per IC) *</label>
                <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name">
            </div>
            
            <div class="form-group">
                <label for="ic_number">IC Number *</label>
                <input type="text" id="ic_number" name="ic_number" required placeholder="e.g., 900101-14-5555">
            </div>
            
            <div class="form-group">
                <label for="gender">Gender *</label>
                <select id="gender" name="gender" required>
                    <option value="" disabled selected>Select Gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="dob">Date of Birth *</label>
                <input type="date" id="dob" name="dob" required>
            </div>
        </div>

        <div class="driver-card">
            <h3><i class="fas fa-address-book"></i> Contact Information</h3>
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required placeholder="example@email.com">
            </div>
            
            <div class="form-group">
                <label for="phone_number">Phone Number *</label>
                <input type="tel" id="phone_number" name="phone_number" required placeholder="e.g., 0123456789">
            </div>
            
            <div class="form-group">
                <label for="home_address">Current Home Address *</label>
                <textarea id="home_address" name="home_address" rows="3" required placeholder="Enter your full residential address"></textarea>
            </div>
        </div>

        <div class="driver-card">
            <h3><i class="fas fa-id-card"></i> Driving Credentials</h3>
            <div class="form-group">
                <label for="license_number">Driving License Number *</label>
                <input type="text" id="license_number" name="license_number" required>
            </div>
            
            <div class="form-group">
                <label for="license_expiry">Driving License Expiry *</label>
                <input type="date" id="license_expiry" name="license_expiry" required>
            </div>
            
            <div class="form-group">
                <label for="psv_expiry">PSV License Expiry *</label>
                <input type="date" id="psv_expiry" name="psv_expiry" required>
            </div>
            
            <div class="form-group">
                <label for="years_experience">Years of Experience *</label>
                <input type="number" id="years_experience" name="years_experience" min="0" required placeholder="0">
            </div>
        </div>

        <div class="driver-card">
            <h3><i class="fas fa-file-upload"></i> Document Uploads</h3>
            <p class="section-desc">Please upload clear copies of the following documents (Images or PDF only, max 5MB each).</p>
            
            <div class="form-group">
                <label for="doc_profile_pic">Profile Picture *</label>
                <input type="file" id="doc_profile_pic" name="doc_profile_pic" accept="image/*,application/pdf" required>
            </div>
            
            <div class="form-group">
                <label for="doc_ic">Copy of IC (Front & Back) *</label>
                <input type="file" id="doc_ic" name="doc_ic" accept="image/*,application/pdf" required>
            </div>
            
            <div class="form-group">
                <label for="doc_license">Copy of Driving License *</label>
                <input type="file" id="doc_license" name="doc_license" accept="image/*,application/pdf" required>
            </div>
            
            <div class="form-group">
                <label for="doc_psv">Copy of PSV License *</label>
                <input type="file" id="doc_psv" name="doc_psv" accept="image/*,application/pdf" required>
            </div>
        </div>

        <div class="driver-card">
            <h3><i class="fas fa-check-square"></i> Declarations</h3>
            <div class="checkbox-group">
                <input type="checkbox" id="decl_clean_record" name="decl_clean_record" required>
                <label for="decl_clean_record">I declare that I have a clean driving record with no major traffic offenses.</label>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="decl_health_ok" name="decl_health_ok" required>
                <label for="decl_health_ok">I declare that I have no major health issues that would impair my ability to drive safely.</label>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-save"><i class="fas fa-paper-plane"></i> Submit Application</button>
        </div>

    </form>
</div>

<?php include 'layout/footer.php'; ?>

</body>
</html>