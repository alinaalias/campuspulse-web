<?php
require_once '../config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- SMART NAME FORMATTING ---
    $rawName = trim($_POST['full_name'] ?? '');
    // Split by spaces, automatically handling any accidental double spaces
    $words = array_filter(explode(' ', strtolower($rawName)));
    // Common Malaysian name conjunctions to keep lowercase
    $exceptions = ['bin', 'binti', 'bt', 'bte', 'a/l', 'a/p', 'a/k', 'anak', 's/o', 'd/o'];

    $formattedWords = [];
    $isFirstWord = true;

    foreach ($words as $word) {
        if (in_array($word, $exceptions) && !$isFirstWord) {
            $formattedWords[] = strtolower($word);
        } else {
            $formattedWords[] = ucfirst($word);
        }
        $isFirstWord = false;
    }

    $fullName = implode(' ', $formattedWords);
    // ------------------------------

    $icNumber = $_POST['ic_number'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $email = $_POST['email'] ?? '';
    $phoneNumber = $_POST['phone_number'] ?? '';
    $homeAddress = $_POST['home_address'] ?? '';
    $licenseNumber = $_POST['license_number'] ?? '';
    $licenseExpiry = $_POST['license_expiry'] ?? '';
    $psvExpiry = $_POST['psv_expiry'] ?? '';
    $yearsExperience = isset($_POST['years_experience']) ? (int) $_POST['years_experience'] : 0;

    $declCleanRecord = isset($_POST['decl_clean_record']);
    $declHealthOk = isset($_POST['decl_health_ok']);

    try {
        $db = $firestore;

        // --- NEW: DUPLICATE GATEKEEPER START ---

        // 1. Check if they are already an active driver in 'Staffs'
        $existingStaffIC = $db->collection('Staffs')->where('ic_number', '=', $icNumber)->documents();
        $existingStaffEmail = $db->collection('Staffs')->where('email', '=', $email)->documents();

        if (!$existingStaffIC->isEmpty() || !$existingStaffEmail->isEmpty()) {
            $_SESSION['error'] = "An account with this IC Number or Email is already registered as an active CampusPulse Driver.";
            header('Location: driver_application.php');
            exit();
        }

        // 2. Check if they already have a 'pending' application
        $pendingApps = $db->collection('DriverApplications')
            ->where('ic_number', '=', $icNumber)
            ->where('status', '=', 'pending')
            ->documents();

        if (!$pendingApps->isEmpty()) {
            $_SESSION['error'] = "You already have a pending application. Please wait for the admin to review it.";
            header('Location: driver_application.php');
            exit();
        }

        // --- DUPLICATE GATEKEEPER END ---


        // --- FILE UPLOAD LOGIC (Unchanged) ---
        $filesToUpload = [
            'doc_profile_pic' => $_FILES['doc_profile_pic'] ?? null,
            'doc_ic' => $_FILES['doc_ic'] ?? null,
            'doc_license' => $_FILES['doc_license'] ?? null,
            'doc_psv' => $_FILES['doc_psv'] ?? null
        ];

        $uploadedPaths = [];
        $collection = $db->collection('DriverApplications');

        foreach ($filesToUpload as $key => $file) {
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $uniqueName = uniqid() . '_' . time() . '.' . $ext;
                $objectName = 'driver_applications/' . $uniqueName;

                $uploadStream = fopen($file['tmp_name'], 'r');

                $bucket->upload($uploadStream, [
                    'name' => $objectName,
                    'metadata' => [
                        'contentType' => $file['type']
                    ]
                ]);

                //fclose($uploadStream);

                $uploadedPaths[$key] = $objectName;
            } else {
                throw new Exception("Error uploading $key. Please ensure all files are provided and valid.");
            }
        }

        // --- PREPARE DATA ---
        $applicationData = [
            'full_name' => $fullName,
            'ic_number' => $icNumber,
            'gender' => $gender,
            'dob' => $dob,
            'email' => $email,
            'phone_number' => $phoneNumber,
            'home_address' => $homeAddress,
            'license_number' => $licenseNumber,
            'license_expiry' => $licenseExpiry,
            'psv_expiry' => $psvExpiry,
            'years_experience' => $yearsExperience,
            'decl_clean_record' => $declCleanRecord,
            'decl_health_ok' => $declHealthOk,
            'status' => 'pending',
            'applied_at' => date('c'),
        ];

        $applicationData = array_merge($applicationData, $uploadedPaths);

        $collection->add($applicationData);

        $_SESSION['success'] = 'Your application has been submitted successfully. The admin will review your application and notify you of the result via email. This process will take 3-4 business days.';
        header('Location: driver_application.php');
        exit;

    } catch (Exception $e) {
        $_SESSION['error'] = 'Application submission failed: ' . $e->getMessage();
        header('Location: driver_application.php');
        exit;
    }
} else {
    header('Location: driver_application.php');
    exit;
}
?>