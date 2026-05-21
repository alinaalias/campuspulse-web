<?php
require_once '../config.php';
session_start();


// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

try {
    $db = $firestore;
    $applicationsRef = $db->collection('DriverApplications')->where('status', '=', 'pending');
    $snapshot = $applicationsRef->documents();

    $pendingApplications = [];
    foreach ($snapshot as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $data['id'] = $doc->id();
            $pendingApplications[] = $data;
        }
    }
} catch (Exception $e) {
    $errorMsg = "Error fetching applications: " . $e->getMessage();
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
            // URL expires in 15 minutes
            return $object->signedUrl(new \DateTime('+15 minutes'));
        }
    } catch (Exception $e) {
        // Suppress and fallback to generic hash if error occurs
    }
    return '#';
}

$pageTitle = 'Review Driver Applications - CampusPulse';
$depth = '../';
include $depth . 'layout/admin/header.php';
?>

<style>
    .applications-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .driver-card {
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        border: 1px solid #eaeaea;
        display: flex;
        flex-direction: column;
    }

    .driver-card h3 {
        margin-top: 0;
        color: #003366;
        font-size: 1.2rem;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .status-badge {
        font-size: 0.8rem;
        background: #fff3cd;
        color: #856404;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 500;
    }

    .app-detail {
        margin-bottom: 10px;
        font-size: 0.95rem;
        color: #444;
        display: flex;
        align-items: center;
    }

    .app-detail i {
        margin-right: 10px;
        width: 16px;
        text-align: center;
        color: #666;
    }

    .app-detail strong {
        font-weight: 600;
        color: #333;
        margin-right: 5px;
    }

    .docs-section {
        margin-top: auto;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #eef0f2;
    }

    .docs-section h4 {
        margin: 0 0 10px 0;
        font-size: 1rem;
        color: #555;
        display: flex;
        align-items: center;
    }

    .docs-section h4 i {
        margin-right: 8px;
        color: #003366;
    }

    .docs-links-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .doc-link {
        display: flex;
        align-items: center;
        color: #0066cc;
        text-decoration: none;
        font-size: 0.85rem;
        background: #fff;
        padding: 6px 10px;
        border: 1px solid #cce5ff;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .doc-link:hover {
        background: #e6f2ff;
        text-decoration: none;
        border-color: #99ccff;
    }

    .doc-link i {
        margin-right: 6px;
    }

    .action-buttons {
        margin-top: 15px;
        display: flex;
        gap: 10px;
    }

    .btn-approve,
    .btn-reject {
        border: none;
        padding: 10px 15px;
        border-radius: 6px;
        cursor: pointer;
        font-family: inherit;
        font-weight: 600;
        text-decoration: none;
        flex: 1;
        text-align: center;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease;
    }

    .btn-approve {
        background: #28a745;
        color: white;
        box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
    }

    .btn-approve:hover {
        background: #218838;
    }

    .btn-reject {
        background: #dc3545;
        color: white;
        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
    }

    .btn-reject:hover {
        background: #c82333;
    }

    .btn-approve i,
    .btn-reject i {
        margin-right: 8px;
    }

    .empty-state {
        background: #fff;
        padding: 50px 20px;
        text-align: center;
        border-radius: 12px;
        border: 1px dashed #ccc;
        color: #666;
        margin-top: 20px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }

    .empty-state h3 {
        margin-top: 0;
        color: #333;
    }

    .empty-state p {
        margin-bottom: 0;
        font-size: 1.1rem;
    }
</style>

<h2 class="page-title">Review Driver Applications</h2>

<?php if (isset($errorMsg)): ?>
    <div class="alert alert-error"
        style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #f5c6cb;">
        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"
        style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #c3e6cb;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']);
        unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error"
        style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #f5c6cb;">
        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error']);
        unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<?php if (empty($pendingApplications) && !isset($errorMsg)): ?>
    <div class="empty-state">
        <i class="fas fa-inbox fa-4x" style="color: #dee2e6; margin-bottom: 20px;"></i>
        <h3>No pending applications</h3>
        <p>There are currently no new driver applications submitted for review.</p>
    </div>
<?php elseif (!empty($pendingApplications)): ?>
    <div class="applications-grid">
        <?php foreach ($pendingApplications as $app): ?>
            <div class="driver-card">
                <h3>
                    <?= htmlspecialchars($app['full_name'] ?? 'Unknown Name') ?>
                    <span class="status-badge">Pending</span>
                </h3>

                <div class="app-detail">
                    <i class="fas fa-id-card"></i>
                    <strong>IC:</strong> <?= htmlspecialchars($app['ic_number'] ?? 'N/A') ?>
                </div>
                <div class="app-detail">
                    <i class="fas fa-phone-alt"></i>
                    <strong>Phone:</strong> <?= htmlspecialchars($app['phone_number'] ?? 'N/A') ?>
                </div>
                <div class="app-detail">
                    <i class="fas fa-calendar-alt"></i>
                    <strong>Applied:</strong>
                    <?= isset($app['applied_at']) ? htmlspecialchars(date('M j, Y, g:i a', strtotime($app['applied_at']))) : 'Unknown' ?>
                </div>

                <div class="docs-section">
                    <h4><i class="fas fa-folder-open"></i> Uploaded Documents</h4>
                    <div class="docs-links-grid">
                        <a href="<?= htmlspecialchars(getSignedUrl($bucket, $app['doc_profile_pic'] ?? null)) ?>"
                            target="_blank" class="doc-link">
                            <i class="fas fa-image"></i> Profile Pic
                        </a>
                        <a href="<?= htmlspecialchars(getSignedUrl($bucket, $app['doc_ic'] ?? null)) ?>" target="_blank"
                            class="doc-link">
                            <i class="fas fa-address-card"></i> IC Copy
                        </a>
                        <a href="<?= htmlspecialchars(getSignedUrl($bucket, $app['doc_license'] ?? null)) ?>" target="_blank"
                            class="doc-link">
                            <i class="fas fa-id-badge"></i> License
                        </a>
                        <a href="<?= htmlspecialchars(getSignedUrl($bucket, $app['doc_psv'] ?? null)) ?>" target="_blank"
                            class="doc-link">
                            <i class="fas fa-file-invoice"></i> PSV License
                        </a>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="admin_view_application.php?id=<?= urlencode($app['id']) ?>"
                        style="background: #003366; color: white; border: none; padding: 10px 15px; border-radius: 6px; text-align: center; text-decoration: none; width: 100%; display: block; font-weight: 600; transition: background 0.2s;">
                        <i class="fas fa-search"></i> Review Full Application
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
</div>
</div>
<?php include $depth . 'layout/admin/footer.php'; ?>