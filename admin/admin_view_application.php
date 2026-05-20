<?php
require_once '../config.php';
session_start();

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: admin_review_drivers.php');
    exit();
}

$applicationId = $_GET['id'];
$applicationData = null;

try {
    $db = $firestore->database();
    $docRef = $db->collection('DriverApplications')->document($applicationId);
    $snapshot = $docRef->snapshot();

    if ($snapshot->exists()) {
        $applicationData = $snapshot->data();
        $applicationData['id'] = $snapshot->id();
    } else {
        $_SESSION['error'] = 'Application not found.';
        header('Location: admin_review_drivers.php');
        exit();
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Error fetching application details: " . $e->getMessage();
    header('Location: admin_review_drivers.php');
    exit();
}

/**
 * Generate a signed URL for a file in Google Cloud Storage
 */
function getSignedUrl($bucket, $filePath)
{
    if (!$filePath)
        return '#';
    try {
        $object = $bucket->object($filePath);
        if ($object->exists()) {
            return $object->signedUrl(new \DateTime('+15 minutes'));
        }
    } catch (Exception $e) {
        // Suppress and fallback
    }
    return '#';
}

$pageTitle = 'Review Application - CampusPulse';
$depth = '../';
include $depth . 'layout/admin/header.php';
?>

<style>
    .review-container {
        max-width: 900px;
        margin: 0 auto;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 20px;
        color: #555;
        text-decoration: none;
        font-size: 0.95rem;
        transition: color 0.2s;
    }

    .back-link:hover {
        color: #003366;
        text-decoration: underline;
    }

    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        border-bottom: 2px solid #eaeaea;
        padding-bottom: 15px;
    }

    .review-header h2 {
        margin: 0;
        color: #003366;
        font-size: 1.8rem;
    }

    .status-badge {
        font-size: 0.9rem;
        padding: 6px 15px;
        border-radius: 20px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-accepted {
        background: #d4edda;
        color: #155724;
    }

    .status-rejected {
        background: #f8d7da;
        color: #721c24;
    }

    .details-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 30px;
    }

    .detail-card {
        background: #fff;
        border-radius: 8px;
        padding: 25px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        border: 1px solid #eaeaea;
    }

    .detail-card.full-width {
        grid-column: 1 / -1;
    }

    .detail-card h3 {
        margin-top: 0;
        margin-bottom: 20px;
        color: #003366;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }

    .detail-card h3 i {
        margin-right: 10px;
        color: #666;
    }

    .info-row {
        display: flex;
        margin-bottom: 12px;
        font-size: 0.95rem;
    }

    .info-label {
        width: 140px;
        font-weight: 600;
        color: #555;
        flex-shrink: 0;
    }

    .info-value {
        color: #333;
        flex-grow: 1;
        word-break: break-word;
    }

    /* Declarations */
    .declaration-item {
        margin-bottom: 15px;
        display: flex;
        align-items: flex-start;
    }

    .declaration-status {
        margin-right: 15px;
        font-size: 1.1rem;
    }

    .status-yes {
        color: #28a745;
    }

    .status-no {
        color: #dc3545;
    }

    .declaration-text {
        color: #444;
        line-height: 1.4;
    }

    /* Documents Grid */
    .docs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .doc-item {
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 15px;
        text-align: center;
        background: #fcfcfc;
        transition: transform 0.2s;
    }

    .doc-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
    }

    .doc-icon {
        font-size: 2.5rem;
        color: #0066cc;
        margin-bottom: 10px;
    }

    .doc-name {
        font-weight: 500;
        color: #333;
        margin-bottom: 15px;
        font-size: 0.95rem;
    }

    .btn-view-doc {
        display: inline-block;
        background: #e6f2ff;
        color: #0066cc;
        padding: 8px 15px;
        border-radius: 4px;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        transition: background 0.2s;
    }

    .btn-view-doc:hover {
        background: #cce5ff;
    }

    /* Actions Footer */
    .actions-footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 2px solid #eaeaea;
        display: flex;
        justify-content: flex-end;
        gap: 15px;
    }

    .btn-action {
        padding: 12px 25px;
        border-radius: 6px;
        font-weight: 600;
        text-decoration: none;
        font-size: 1rem;
        display: flex;
        align-items: center;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
    }

    .btn-action i {
        margin-right: 8px;
    }

    .btn-approve {
        background: #28a745;
        color: white;
    }

    .btn-approve:hover {
        background: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
    }

    .btn-reject {
        background: #dc3545;
        color: white;
    }

    .btn-reject:hover {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(220, 53, 69, 0.2);
    }

    @media (max-width: 768px) {
        .details-grid {
            grid-template-columns: 1fr;
        }

        .review-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .actions-footer {
            flex-direction: column;
        }

        .btn-action {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="review-container">
    <a href="admin_review_drivers.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Pending Applications
    </a>

    <div class="review-header">
        <h2>Applicant Profile: <?= htmlspecialchars($applicationData['full_name'] ?? 'Unknown Name') ?>
        </h2>
        <?php
        $status = $applicationData['status'] ?? 'pending';
        $statusClass = 'status-' . strtolower($status);
        ?>
        <span class="status-badge <?= $statusClass ?>">
            <?= htmlspecialchars(ucfirst($status)) ?>
        </span>
    </div>

    <div class="details-grid">

        <div class="detail-card">
            <h3><i class="fas fa-user"></i> Personal Details</h3>
            <div class="info-row">
                <span class="info-label">Full Name:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['full_name'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">IC Number:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['ic_number'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Gender:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['gender'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date of Birth:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['dob'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Applied On:</span>
                <span class="info-value">
                    <?= isset($applicationData['applied_at']) ? htmlspecialchars(date('F j, Y, g:i a', strtotime($applicationData['applied_at']))) : 'N/A' ?>
                </span>
            </div>
        </div>

        <div class="detail-card">
            <h3><i class="fas fa-address-book"></i> Contact Info</h3>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value"><a
                        href="mailto:<?= htmlspecialchars($applicationData['email'] ?? '') ?>"><?= htmlspecialchars($applicationData['email'] ?? 'N/A') ?></a></span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['phone_number'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row" style="flex-direction: column;">
                <span class="info-label" style="margin-bottom: 5px;">Home Address:</span>
                <span class="info-value"
                    style="background: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px solid #eee;">
                    <?= nl2br(htmlspecialchars($applicationData['home_address'] ?? 'N/A')) ?>
                </span>
            </div>
        </div>

        <div class="detail-card">
            <h3><i class="fas fa-id-card"></i> Driving Credentials</h3>
            <div class="info-row">
                <span class="info-label">License Number:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['license_number'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">License Expiry:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['license_expiry'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">PSV Expiry:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['psv_expiry'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Years Experience:</span>
                <span class="info-value"><?= htmlspecialchars($applicationData['years_experience'] ?? '0') ?>
                    Years</span>
            </div>
        </div>

        <div class="detail-card">
            <h3><i class="fas fa-check-square"></i> Declarations</h3>

            <div class="declaration-item">
                <div
                    class="declaration-status <?= ($applicationData['decl_clean_record'] ?? false) ? 'status-yes' : 'status-no' ?>">
                    <i
                        class="fas <?= ($applicationData['decl_clean_record'] ?? false) ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                </div>
                <div class="declaration-text">
                    <strong>Clean Driving Record:</strong><br>
                    Applicant declares a clean driving record with no major traffic offenses.
                </div>
            </div>

            <div class="declaration-item">
                <div
                    class="declaration-status <?= ($applicationData['decl_health_ok'] ?? false) ? 'status-yes' : 'status-no' ?>">
                    <i
                        class="fas <?= ($applicationData['decl_health_ok'] ?? false) ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                </div>
                <div class="declaration-text">
                    <strong>Health Declaration:</strong><br>
                    Applicant declares no major health issues that would impair driving ability.
                </div>
            </div>
        </div>

        <div class="detail-card full-width">
            <h3><i class="fas fa-folder-open"></i> Accompanying Documents</h3>
            <div class="docs-grid">

                <div class="doc-item">
                    <div class="doc-icon"><i class="fas fa-user-circle"></i></div>
                    <div class="doc-name">Profile Picture</div>
                    <a href="<?= htmlspecialchars(getSignedUrl($bucket, $applicationData['doc_profile_pic'] ?? null)) ?>"
                        target="_blank" class="btn-view-doc">
                        <i class="fas fa-external-link-alt"></i> View File
                    </a>
                </div>

                <div class="doc-item">
                    <div class="doc-icon"><i class="fas fa-address-card"></i></div>
                    <div class="doc-name">IC (Front & Back)</div>
                    <a href="<?= htmlspecialchars(getSignedUrl($bucket, $applicationData['doc_ic'] ?? null)) ?>"
                        target="_blank" class="btn-view-doc">
                        <i class="fas fa-external-link-alt"></i> View File
                    </a>
                </div>

                <div class="doc-item">
                    <div class="doc-icon"><i class="fas fa-id-badge"></i></div>
                    <div class="doc-name">Driving License</div>
                    <a href="<?= htmlspecialchars(getSignedUrl($bucket, $applicationData['doc_license'] ?? null)) ?>"
                        target="_blank" class="btn-view-doc">
                        <i class="fas fa-external-link-alt"></i> View File
                    </a>
                </div>

                <div class="doc-item">
                    <div class="doc-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="doc-name">PSV License</div>
                    <a href="<?= htmlspecialchars(getSignedUrl($bucket, $applicationData['doc_psv'] ?? null)) ?>"
                        target="_blank" class="btn-view-doc">
                        <i class="fas fa-external-link-alt"></i> View File
                    </a>
                </div>

            </div>
        </div>

    </div>

    <?php if (strtolower($applicationData['status'] ?? '') === 'pending'): ?>
        <div class="actions-footer">
            <button type="button" class="btn-action btn-reject" onclick="openRejectModal()">
                <i class="fas fa-times-circle"></i> Reject
            </button>

            <button type="button" class="btn-action btn-approve" onclick="openApproveModal()">
                <i class="fas fa-check-circle"></i> Approve
            </button>
        </div>
    <?php endif; ?>

</div>

</div>
</div>
</div>

<div id="rejectModal"
    style="display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div
        style="background:white; padding:30px; border-radius:12px; width:90%; max-width:500px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#dc3545; border-bottom: 1px solid #eee; padding-bottom: 15px;">
            <i class="fas fa-exclamation-circle"></i> Reject Application
        </h3>
        <p style="color:#555; font-size: 0.95rem;">Please select or provide a reason for rejecting this application.
            This will be sent directly to the applicant's email.</p>

        <form action="process_application.php" method="POST">
            <input type="hidden" name="id" value="<?= htmlspecialchars($applicationId) ?>">
            <input type="hidden" name="action" value="reject">

            <label style="font-weight:600; display:block; margin-bottom:8px; color: #333;">Reason for
                Rejection:</label>
            <select name="reject_reason_preset" id="rejectPreset"
                style="margin-bottom:15px; width:100%; padding:10px; border-radius: 6px; border: 1px solid #ccc;"
                onchange="toggleCustomReason()">
                <option value="Incomplete or Unclear Documents">Incomplete or Unclear Documents</option>
                <option value="Invalid or Expired License">Invalid or Expired License</option>
                <option value="Insufficient Driving Experience">Insufficient Driving Experience</option>
                <option value="Did not pass background/health check">Did not pass health/background requirements
                </option>
                <option value="custom">Other (Please specify below)</option>
            </select>

            <textarea name="reject_reason_custom" id="customReason" rows="4" placeholder="Type specific reason here..."
                style="display:none; width:100%; padding:10px; margin-bottom:15px; border-radius: 6px; border: 1px solid #ccc; font-family: inherit; resize: vertical;"></textarea>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                <button type="button"
                    style="padding: 10px 20px; border-radius: 6px; border: none; background:#eee; color:#333; cursor:pointer; font-weight:600;"
                    onclick="closeRejectModal()">Cancel</button>
                <button type="submit"
                    style="padding: 10px 20px; border-radius: 6px; border: none; background:#dc3545; color:white; cursor:pointer; font-weight:600;">
                    <i class="fas fa-paper-plane"></i> Send Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<div id="approveModal"
    style="display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div
        style="background:white; padding:30px; border-radius:12px; width:90%; max-width:500px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#28a745; border-bottom: 1px solid #eee; padding-bottom: 15px;">
            <i class="fas fa-check-circle"></i> Approve Application
        </h3>
        <p style="color:#555; font-size: 0.95rem;">Are you sure you want to <strong>APPROVE</strong> this applicant?
            They will be officially added to the active Drivers list and notified via email.</p>

        <form action="process_application.php" method="POST">
            <input type="hidden" name="id" value="<?= htmlspecialchars($applicationId) ?>">
            <input type="hidden" name="action" value="approve">

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                <button type="button"
                    style="padding: 10px 20px; border-radius: 6px; border: none; background:#eee; color:#333; cursor:pointer; font-weight:600;"
                    onclick="closeApproveModal()">Cancel</button>
                <button type="submit"
                    style="padding: 10px 20px; border-radius: 6px; border: none; background:#28a745; color:white; cursor:pointer; font-weight:600;">
                    <i class="fas fa-check"></i> Confirm Approval
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openRejectModal() {
        document.getElementById('rejectModal').style.display = 'flex';
    }

    function closeRejectModal() {
        document.getElementById('rejectModal').style.display = 'none';
    }

    function openApproveModal() {
        document.getElementById('approveModal').style.display = 'flex';
    }

    function closeApproveModal() {
        document.getElementById('approveModal').style.display = 'none';
    }

    function toggleCustomReason() {
        const preset = document.getElementById('rejectPreset').value;
        const customBox = document.getElementById('customReason');
        if (preset === 'custom') {
            customBox.style.display = 'block';
            customBox.required = true;
        } else {
            customBox.style.display = 'none';
            customBox.required = false;
        }
    }
</script>
<?php include $depth . 'layout/admin/footer.php'; ?>