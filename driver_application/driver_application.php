<?php
session_start();
require_once '../config.php'; // Updated path for subfolder

$depth = '../';
$pageTitle = 'Become a Driver – CampusPulse';

ob_start();
?>
<style>
    body {
        background-color: #f8f9fa;
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
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
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
        display: flex;
        align-items: center;
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

    /* Address Grid */
    .address-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        background: #fcfcfc;
        padding: 20px;
        border: 1px solid #eee;
        border-radius: 8px;
    }
    
    .address-grid .form-group {
        margin-bottom: 0;
    }

    .address-grid .full-width {
        grid-column: 1 / -1;
    }

    /* Tooltip Styles */
    .tooltip-wrapper {
        position: relative;
        display: inline-block;
        margin-left: 8px;
    }

    .tooltip-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        background: #003366;
        color: #fff;
        border-radius: 50%;
        font-size: 0.75rem;
        cursor: help;
        font-weight: bold;
    }

    .tooltip-text {
        visibility: hidden;
        width: 260px;
        background-color: #333;
        color: #fff;
        text-align: left;
        border-radius: 6px;
        padding: 10px 12px;
        position: absolute;
        z-index: 10;
        bottom: 130%;
        left: 50%;
        margin-left: -130px;
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 0.8rem;
        line-height: 1.5;
        font-weight: normal;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .tooltip-text::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border-width: 5px;
        border-style: solid;
        border-color: #333 transparent transparent transparent;
    }

    .tooltip-wrapper:hover .tooltip-text {
        visibility: visible;
        opacity: 1;
    }

    /* Success/Error Alerts */
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 25px;
        font-weight: 500;
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    /* Global Loader Overlay */
    .global-loader-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(5px);
        z-index: 99999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .global-loader-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .loader-spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid #003366;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loader-text {
        color: #003366;
        font-weight: 600;
        font-size: 1.1rem;
    }
</style>
<?php
$extraHead = ob_get_clean();
include $depth . 'layout/public/header.php';
?>

<div class="page-banner">
    <h1>Join the CampusPulse Fleet</h1>
    <p>Help fellow students commute safely while earning. Apply to become an official UniKL shuttle driver today.</p>
</div>

<div class="application-container">

    <?php
    // Display success or error messages
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
    ?>

    <form action="submit_application.php" method="POST" enctype="multipart/form-data" class="driver-application-form"
        onsubmit="showGlobalLoader('Submitting Application & Uploading Documents...')">

        <div class="driver-card">
            <h3><i class="fas fa-user"></i> Personal Details</h3>
            <div class="form-group">
                <label for="full_name">Full Name (as per IC) *</label>
                <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name">
            </div>

            <div class="form-group">
                <label for="ic_number">IC Number (without dashes) *</label>
                <input type="text" id="ic_number" name="ic_number" required placeholder="e.g., 900101145555" maxlength="12" pattern="\d{12}" title="Please enter exactly 12 numbers with no dashes">
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
                <input type="date" id="dob" name="dob" required readonly style="background-color: #e9ecef; cursor: not-allowed; color:#666;">
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
                <label>Current Home Address *</label>
                <input type="hidden" id="home_address" name="home_address" required>
                
                <div class="address-grid">
                    <div class="form-group full-width">
                        <input type="text" id="addr_line1" required placeholder="Address Line 1 (House/Unit No., Street Name)">
                    </div>
                    <div class="form-group full-width">
                        <input type="text" id="addr_line2" placeholder="Address Line 2 (Taman/Kampung/Building) - Optional">
                    </div>
                    <div class="form-group">
                        <input type="text" id="addr_postcode" required placeholder="Postcode" maxlength="5" pattern="\d{5}">
                    </div>
                    <div class="form-group">
                        <input type="text" id="addr_city" required placeholder="City">
                    </div>
                    <div class="form-group full-width">
                        <select id="addr_state" required>
                            <option value="" disabled selected>Select State</option>
                            <option value="Johor">Johor</option>
                            <option value="Kedah">Kedah</option>
                            <option value="Kelantan">Kelantan</option>
                            <option value="Melaka">Melaka</option>
                            <option value="Negeri Sembilan">Negeri Sembilan</option>
                            <option value="Pahang">Pahang</option>
                            <option value="Perak">Perak</option>
                            <option value="Perlis">Perlis</option>
                            <option value="Pulau Pinang">Pulau Pinang</option>
                            <option value="Sabah">Sabah</option>
                            <option value="Sarawak">Sarawak</option>
                            <option value="Selangor">Selangor</option>
                            <option value="Terengganu">Terengganu</option>
                            <option value="WP Kuala Lumpur">WP Kuala Lumpur</option>
                            <option value="WP Labuan">WP Labuan</option>
                            <option value="WP Putrajaya">WP Putrajaya</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="driver-card">
            <h3><i class="fas fa-id-card"></i> Driving Credentials</h3>
            <div class="form-group">
                <label for="license_number">
                    Driving License Serial Number
                    <div class="tooltip-wrapper">
                        <span class="tooltip-icon">?</span>
                        <span class="tooltip-text"> Take a look at the back of your driving license card. It is typically located in the top-left corner.</span>
                    </div>
                    &nbsp;*
                </label>
                <input type="text" id="license_number" name="license_number" required placeholder="e.g., IZYYiLNg">
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
            <p class="section-desc">Please upload clear copies of the following documents (Images or PDF only, max 5MB
                each).</p>

            <div class="form-group">
                <label for="doc_profile_pic">Passport Image *</label>
                <input type="file" id="doc_profile_pic" name="doc_profile_pic" accept="image/*,application/pdf"
                    required>
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
            
            <div class="checkbox-group" style="background-color: #e8f4fd; border-color: #bbdefb;">
                <input type="checkbox" id="decl_pdpa" name="decl_pdpa" required>
                <label for="decl_pdpa" style="color: #003366;">
                    <b>Data Privacy Consent:</b> I agree to the Terms & Policies and consent to the collection, processing, and storage of my personal data (including my Identity Card and Driving Licenses) strictly for background verification and onboarding purposes, in compliance with the Personal Data Protection Act (PDPA).
                </label>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="decl_clean_record" name="decl_clean_record" required>
                <label for="decl_clean_record">I declare that I have a clean driving record with no major traffic
                    offenses.</label>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="decl_health_ok" name="decl_health_ok" required>
                <label for="decl_health_ok">I declare that I have no major health issues that would impair my ability to
                    drive safely.</label>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-save" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit
                Application</button>
        </div>

    </form>
</div>

<div class="global-loader-overlay" id="globalLoader">
    <div class="loader-spinner"></div>
    <div class="loader-text" id="globalLoaderText">Processing...</div>
</div>

<script>
    // Global Loader
    function showGlobalLoader(message = 'Processing...') {
        document.getElementById('globalLoaderText').innerText = message;
        document.getElementById('globalLoader').classList.add('active');
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
    }

    // FEATURE 1 & 2: IC Number formatting and DOB Auto-detect
    const icInput = document.getElementById('ic_number');
    const dobInput = document.getElementById('dob');

    icInput.addEventListener('input', function(e) {
        // Force numbers only & remove dashes dynamically as user types
        this.value = this.value.replace(/[^0-9]/g, '');

        if (this.value.length >= 6) {
            let yy = parseInt(this.value.substring(0, 2));
            let mm = this.value.substring(2, 4);
            let dd = this.value.substring(4, 6);

            // Determine Century (Standard rule: if year is greater than current year's last 2 digits, it's 1900s)
            let currentYear = new Date().getFullYear();
            let currentYY = currentYear % 100;
            let century = (yy > currentYY) ? 1900 : 2000;
            let fullYear = century + yy;

            // Simple validation before setting value
            if (mm >= 1 && mm <= 12 && dd >= 1 && dd <= 31) {
                dobInput.value = `${fullYear}-${mm}-${dd}`;
            }
        } else {
            dobInput.value = '';
        }
    });

    // FEATURE 3: Seamless Address Assembly
    // This merges your new inputs into the hidden "home_address" field so your backend submit_application.php doesn't break
    function buildHiddenAddress() {
        const line1 = document.getElementById('addr_line1').value.trim();
        const line2 = document.getElementById('addr_line2').value.trim();
        const postcode = document.getElementById('addr_postcode').value.trim();
        const city = document.getElementById('addr_city').value.trim();
        const state = document.getElementById('addr_state').value || '';

        let fullAddress = line1;
        if (line2) fullAddress += `, ${line2}`;
        if (postcode || city) fullAddress += `, ${postcode} ${city}`;
        if (state) fullAddress += `, ${state}`;

        document.getElementById('home_address').value = fullAddress;
    }

    const addressFields = ['addr_line1', 'addr_line2', 'addr_postcode', 'addr_city', 'addr_state'];
    addressFields.forEach(id => {
        document.getElementById(id).addEventListener('input', buildHiddenAddress);
        document.getElementById(id).addEventListener('change', buildHiddenAddress);
    });

</script>

<?php include $depth . 'layout/public/footer.php'; ?>