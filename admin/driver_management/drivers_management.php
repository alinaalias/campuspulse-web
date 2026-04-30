<?php
session_start();
require_once '../../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

// 1. Get Search Term
$search = trim($_GET['search'] ?? '');

/* Fetch shuttles */
$shuttles = [];
foreach ($firestore->database()->collection('Shuttles')->where('status', '=', 'active')->documents() as $s) {
    if ($s->exists())
        $shuttles[] = $s->id();
}

/* Fetch drivers */
$driversSnapshot = $firestore->database()->collection('Staffs')->where('role', '=', 'driver')->documents();
$drivers = [];

$totalActive = 0;
$totalUnassigned = 0;
$criticalActions = 0;
$totalPending = 0;

$todayDate = new DateTime('today');

foreach ($driversSnapshot as $doc) {
    if (!$doc->exists())
        continue;
    $data = $doc->data();
    $data['id'] = $doc->id();

    // --- Expiration Math ---
    $licExp = $data['license_expiry'] ?? '';
    $psvExp = $data['psv_expiry'] ?? '';

    $licObj = !empty($licExp) ? new DateTime($licExp) : null;
    $psvObj = !empty($psvExp) ? new DateTime($psvExp) : null;

    $licDays = $licObj ? (int) $todayDate->diff($licObj)->format('%r%a') : null;
    $psvDays = $psvObj ? (int) $todayDate->diff($psvObj)->format('%r%a') : null;

    $data['lic_days'] = $licDays;
    $data['psv_days'] = $psvDays;

    $isLicWarning = ($licDays !== null && $licDays <= 30 && $licDays >= 0);
    $isPsvWarning = ($psvDays !== null && $psvDays <= 30 && $psvDays >= 0);
    $isLicExp = ($licDays !== null && $licDays < 0);
    $isPsvExp = ($psvDays !== null && $psvDays < 0);
    $data['is_expired'] = ($isLicExp || $isPsvExp);

    if ($isLicWarning || $isPsvWarning || $isLicExp || $isPsvExp) {
        $criticalActions++;
    }

    // --- NEW: COMPLIANCE LOCK LOGIC ---
    if ($data['is_expired'] && ($data['status'] ?? '') === 'active') {
        $firestore->database()->collection('Staffs')->document($doc->id())->update([
            ['path' => 'status', 'value' => 'inactive'],
            ['path' => 'last_status_change_reason', 'value' => 'System Auto-Lock (Compliance Expired)'],
            ['path' => 'last_status_change_at', 'value' => date('Y-m-d H:i:s')],
            ['path' => 'last_status_change_admin', 'value' => 'System']
        ]);
        $data['status'] = 'inactive';
        $data['last_status_change_reason'] = 'System Auto-Lock (Compliance Expired)';
        $data['last_status_change_at'] = date('Y-m-d H:i:s');
        $data['last_status_change_admin'] = 'System';
    }

    if (($data['status'] ?? '') === 'active') {
        $totalActive++;
    }

    if (($data['status'] ?? '') === 'pending_review') {
        $totalPending++;
    }

    if (empty($data['assigned_shuttle_id'])) {
        $totalUnassigned++;
    }

    // --- NEW: DOCUMENT IMAGE PROCESSING (FIX FOR VIEWING) ---
    // We convert the Storage Paths (e.g. driver_credentials/DRV001_license.jpeg) into viewable URLs
    $imgFields = ['profile_pic', 'license_pic', 'psv_pic'];
    foreach ($imgFields as $field) {
        $val = $data[$field] ?? '';
        if (empty($val)) {
            $data[$field . '_url'] = ($field === 'profile_pic') ? "https://cdn-icons-png.flaticon.com/512/149/149071.png" : "";
        } elseif (strpos($val, 'http') === 0) {
            $data[$field . '_url'] = $val;
        } else {
            // Encode path for Firebase Storage URL
            $cleanPath = str_replace('/', '%2F', $val);
            $data[$field . '_url'] = "https://firebasestorage.googleapis.com/v0/b/campuspulse-bfd09.firebasestorage.app/o/{$cleanPath}?alt=media";
        }
    }

    // 2. Filter Logic
    if ($search) {
        $term = strtolower($search);
        $name = strtolower($data['full_name'] ?? '');
        $id = strtolower($data['id']);
        $shuttle = strtolower($data['assigned_shuttle_id'] ?? '');

        if (str_contains($name, $term) || str_contains($id, $term) || str_contains($shuttle, $term)) {
            $drivers[] = $data;
        }
    } else {
        $drivers[] = $data;
    }
}

// 3. DEFINE BADGE FUNCTION
function getBadge($days, $dateStr, $label)
{
    if ($days === null || empty($dateStr))
        return "<div class='badge-cred badge-exp'>{$label}: NO DATA</div>";
    if ($days < 0)
        return "<div class='badge-cred badge-exp'>{$label}: EXPIRED</div>";
    if ($days <= 30)
        return "<div class='badge-cred badge-warn'>{$label}: In {$days} days</div>";
    return "<div class='badge-cred badge-valid'>{$label}: {$dateStr}</div>";
}

$pageTitle = "Drivers Management";
$depth = '../../';
include $depth . 'layout/admin_header.php';
?>


    <style>
        .badge-cred {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 4px;
        }

        .badge-valid {
            background: rgba(46, 204, 113, 0.15);
            color: #27ae60;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }

        .badge-warn {
            background: rgba(243, 156, 18, 0.15);
            color: #d35400;
            border: 1px solid rgba(243, 156, 18, 0.3);
        }

        .badge-exp {
            background: rgba(231, 76, 60, 0.15);
            color: #c0392b;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .filter-card {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border 0.2s;
        }

        .filter-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .filter-card.active-filter {
            border: 2px solid var(--primary-blue) !important;
            background: #fdfdfd;
            box-shadow: inset 0 0 0 2px var(--primary-blue);
        }

        /* Modal UI */
        .hr-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .hr-modal {
            background: white;
            width: 90%;
            max-width: 600px;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: slideUp 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .hr-modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            cursor: pointer;
            color: #888;
            font-size: 1.2rem;
        }

        .hr-modal-close:hover {
            color: #333;
        }

        .hr-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 20px;
        }

        .hr-item label {
            display: block;
            font-size: 0.75rem;
            color: #888;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .hr-item div {
            font-size: 0.95rem;
            color: #333;
            font-weight: 500;
        }

        .driver-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
        }

        .driver-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            padding: 20px;
            border: 1px solid #eee;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
        }

        .driver-card.hidden {
            display: none !important;
        }

        .driver-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .dcard-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
            border-bottom: 1px solid #f5f5f5;
            padding-bottom: 15px;
            min-height: 80px;
        }

        .driver-name-text {
            font-weight: 700;
            color: var(--primary-blue);
            font-size: 1.1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .dcard-img {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-blue);
            background: #fafafa;
            flex-shrink: 0;
        }

        .dcard-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 130px;
        }

        .dcard-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f5f5f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .assignment-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 class="page-title">Fleet HR & Compliance</h2>
                    <a href="add_driver.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Driver</a>
                </div>

                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 25px;">
                    <div class="card filter-card" id="filter-active" onclick="toggleFilter('active')"
                        style="border-left: 4px solid var(--success);">
                        <div style="display:flex; align-items:center; gap: 15px; padding: 20px;">
                            <div
                                style="background: rgba(46, 204, 113, 0.1); padding: 15px; border-radius: 12px; color: var(--success);">
                                <i class="fas fa-users" style="font-size: 1.5rem;"></i></div>
                            <div>
                                <div style="font-size: 0.85rem; color: #777; font-weight: 600;">ACTIVE IN FLEET</div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #333;"><?= $totalActive ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="card filter-card" id="filter-unassigned" onclick="toggleFilter('unassigned')"
                        style="border-left: 4px solid var(--primary-blue);">
                        <div style="display:flex; align-items:center; gap: 15px; padding: 20px;">
                            <div
                                style="background: rgba(52, 152, 219, 0.1); padding: 15px; border-radius: 12px; color: var(--primary-blue);">
                                <i class="fas fa-van-shuttle" style="font-size: 1.5rem;"></i></div>
                            <div>
                                <div style="font-size: 0.85rem; color: #777; font-weight: 600;">UNASSIGNED</div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #333;"><?= $totalUnassigned ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card filter-card" id="filter-critical" onclick="toggleFilter('critical')"
                        style="border-left: 4px solid #e74c3c;">
                        <div style="display:flex; align-items:center; gap: 15px; padding: 20px;">
                            <div
                                style="background: rgba(231, 76, 60, 0.1); padding: 15px; border-radius: 12px; color: #e74c3c;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i></div>
                            <div>
                                <div style="font-size: 0.85rem; color: #777; font-weight: 600;">CRITICAL ACTIONS</div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #333;"><?= $criticalActions ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card filter-card" id="filter-pending" onclick="toggleFilter('pending')"
                        style="border-left: 4px solid #f39c12;">
                        <div style="display:flex; align-items:center; gap: 15px; padding: 20px;">
                            <div
                                style="background: rgba(243, 156, 18, 0.1); padding: 15px; border-radius: 12px; color: #f39c12;">
                                <i class="fas fa-clipboard-check" style="font-size: 1.5rem;"></i></div>
                            <div>
                                <div style="font-size: 0.85rem; color: #777; font-weight: 600;">PENDING REVIEWS</div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #333;"><?= $totalPending ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom: 20px; padding: 15px;">
                    <form method="GET" style="display: flex; gap: 10px;">
                        <input type="text" name="search" class="form-control"
                            placeholder="Search by Name, ID, or Shuttle..." value="<?= htmlspecialchars($search) ?>"
                            style="margin: 0; flex: 1;" id="searchInput">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                        <?php if ($search): ?><a href="drivers_management.php" class="btn"
                                style="background: #eee; color: #333; display: flex; align-items: center;">Reset</a><?php endif; ?>
                    </form>
                </div>

                <?php if (isset($_GET['msg'])): ?>
                    <div
                        style="padding:15px; margin-bottom:20px; border-radius:6px; color:white; background: <?= ($_GET['msg'] === 'deleted' || $_GET['msg'] === 'error') ? '#e74c3c' : '#2ecc71' ?>;">
                        <?php
                        switch ($_GET['msg']) {
                            case 'added':
                                echo "Driver successfully registered!";
                                break;
                            case 'updated':
                                echo "Driver details updated.";
                                break;
                            case 'deleted':
                                echo "Driver deleted.";
                                break;
                            case 'active':
                                echo "Cannot delete active driver. Deactivate first.";
                                break;
                            case 'assigned_error':
                                echo "Unassign shuttle before deleting.";
                                break;
                            default:
                                echo "An error occurred.";
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <div class="driver-card-grid" id="driverGrid">
                    <div id="filterEmptyMessage"
                        style="display:none; grid-column: 1 / -1; text-align: center; padding: 60px 20px; background: white; border-radius: 12px; border: 2px dashed #eee;">
                        <i class="fas fa-clipboard-check"
                            style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                        <h3 id="emptyMessageTitle" style="margin:0; color: #555;">All caught up!</h3>
                        <p id="emptyMessageDesc" style="color: #888; margin-top: 5px;">No drivers match this filter.</p>
                        <button class="btn" style="margin-top:15px; background:#eee;" onclick="toggleFilter('all')">View
                            All Drivers</button>
                    </div>

                    <?php foreach ($drivers as $driver): ?>
                        <div class="driver-card" data-driver-id="<?= htmlspecialchars($driver['id']) ?>"
                            data-active="<?= ($driver['status'] ?? '') === 'active' ? 'true' : 'false' ?>"
                            data-unassigned="<?= empty($driver['assigned_shuttle_id']) ? 'true' : 'false' ?>"
                            data-critical="<?= (($driver['lic_days'] !== null && $driver['lic_days'] <= 30) || ($driver['psv_days'] !== null && $driver['psv_days'] <= 30)) ? 'true' : 'false' ?>"
                            data-pending="<?= ($driver['status'] ?? '') === 'pending_review' ? 'true' : 'false' ?>">

                            <div class="dcard-header">
                                <img src="<?= htmlspecialchars($driver['profile_pic_url']) ?>" class="dcard-img" alt="Img">
                                <div style="overflow: hidden;">
                                    <div class="driver-name-text" title="<?= htmlspecialchars($driver['full_name']) ?>">
                                        <?= htmlspecialchars($driver['full_name']) ?></div>
                                    <div style="font-size:0.85rem; color:#777;"><i class="fas fa-id-badge"></i>
                                        <?= htmlspecialchars($driver['id']) ?></div>
                                    <div style="font-size:0.85rem; color:#777;"><i class="fas fa-phone"></i>
                                        <?= htmlspecialchars($driver['phone_number']) ?></div>
                                </div>
                            </div>

                            <div class="dcard-body">
                                <div>
                                    <?= getBadge($driver['lic_days'], $driver['license_expiry'] ?? '', 'LICENSE') ?><br>
                                    <?= getBadge($driver['psv_days'], $driver['psv_expiry'] ?? '', 'PSV PERMIT') ?>
                                </div>
                                <div class="assignment-box">
                                    <label style="font-size:0.75rem; font-weight:600; color:#888;">ASSIGNED SHUTTLE</label>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <select id="shuttle-<?= $driver['id'] ?>" disabled class="form-control"
                                            style="flex:1; padding:6px; font-size:0.9rem; margin:0;">
                                            <option value="">-- None --</option>
                                            <?php foreach ($shuttles as $sid): ?>
                                                <option value="<?= $sid ?>" <?= ($driver['assigned_shuttle_id'] ?? '') === $sid ? 'selected' : '' ?>><?= $sid ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn" style="padding:6px 10px; background:#e0e0e0;"
                                            onclick="enableEdit('<?= $driver['id'] ?>')"><i
                                                class="fas fa-edit"></i></button>
                                        <button id="save-<?= $driver['id'] ?>" class="btn btn-primary"
                                            onclick="saveAssignment('<?= $driver['id'] ?>')" disabled
                                            style="padding:6px 10px;"><i class="fas fa-save"></i></button>
                                    </div>
                                </div>
                            </div>

                            <div class="dcard-footer">
                                <div style="display:flex; flex-direction:column;">
                                    <div style="display:flex; align-items:center; gap: 10px;">
                                        <?php if ($driver['is_expired']): ?>
                                            <label class="switch"><input type="checkbox" disabled><span class="slider"
                                                    style="background:#e74c3c;"></span></label>
                                            <span style="font-size:0.75rem; color:#e74c3c; font-weight:700;">LOCKED</span>
                                        <?php else: ?>
                                            <label class="switch"><input type="checkbox" <?= ($driver['status'] ?? '') === 'active' ? 'checked' : '' ?>
                                                    onchange="toggleStatus(this, '<?= $driver['id'] ?>')"><span 
                                                    class="slider"></span></label>
                                            <span
                                                style="font-size:0.8rem; color:#666; font-weight:600;"><?= strtoupper($driver['status'] ?? 'INACTIVE') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($driver['is_expired']): ?>
                                        <div
                                            style="font-size:0.65rem; color:#e74c3c; margin-top:4px; max-width: 160px; line-height: 1.2;">
                                            Compliance Error: Valid License & PSV required.</div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <button class="btn"
                                        style="padding:6px 12px; background: var(--primary-blue); color:white;"
                                        onclick="openHrModal('<?= $driver['id'] ?>')"><i class="fas fa-eye"></i>
                                        View</button>
                                    <a href="delete_driver.php?id=<?= $driver['id'] ?>" class="btn danger"
                                        style="padding:6px 10px;" onclick="return confirm('Permanently delete?')"><i
                                            class="fas fa-trash"></i></a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="loadMoreContainer" style="text-align: center; margin-top: 30px; display: none;">
                    <button class="btn btn-primary" onclick="loadMoreCards()"
                        style="padding: 10px 30px; border-radius: 20px;">Load More <i
                            class="fas fa-chevron-down"></i></button>
                    <div style="font-size: 0.8rem; color: #888; margin-top: 5px;" id="loadedCountText"></div>
                </div>
            

    <div id="hrModal" class="hr-modal-overlay" onclick="closeHrModalEvent(event)">
        <div class="hr-modal" id="hrModalBox">
            <i class="fas fa-times hr-modal-close" onclick="closeHrModal()"></i>
            <div style="display:flex; align-items:center; gap:20px; border-bottom:1px solid #eee; padding-bottom:20px;">
                <img id="modalImg" src=""
                    style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--primary-blue);">
                <div>
                    <h3 id="modalName" style="margin:0;">Driver Name</h3>
                    <div style="color:#777; font-size:0.9rem; font-weight:600;"><i class="fas fa-id-card"></i> <span
                            id="modalId">ID</span></div>
                </div>
            </div>
            <div class="hr-grid">
                <div class="hr-item"><label>IC NUMBER</label>
                    <div id="modalIc">Data</div>
                </div>
                <div class="hr-item"><label>EMAIL</label>
                    <div id="modalEmail">Data</div>
                </div>
                <div class="hr-item"><label>GENDER</label>
                    <div id="modalGender">Data</div>
                </div>
                <div class="hr-item"><label>DATE OF BIRTH</label>
                    <div id="modalDob">Data</div>
                </div>
                <div class="hr-item"><label>ADDRESS</label>
                    <div id="modalAddress">Data</div>
                </div>
                <div class="hr-item"><label>EXPERIENCE</label>
                    <div id="modalExp">Data</div>
                </div>
            </div>
            <div style="margin-top:20px;">
                <label
                    style="font-size:0.75rem; color:#888; font-weight:600; display:block; margin-bottom:5px;">CREDENTIALS</label>
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <div style="font-size:0.7rem; color:#aaa;">LICENSE</div>
                        <img id="modalLicImg" src=""
                            style="width:100%; height:100px; object-fit:cover; border-radius:6px; border:1px solid #ddd; cursor:pointer;"
                            onclick="if(this.src.includes('http')) window.open(this.src,'_blank')">
                    </div>
                    <div style="flex:1;">
                        <div style="font-size:0.7rem; color:#aaa;">PSV</div>
                        <img id="modalPsvImg" src=""
                            style="width:100%; height:100px; object-fit:cover; border-radius:6px; border:1px solid #ddd; cursor:pointer;"
                            onclick="if(this.src.includes('http')) window.open(this.src,'_blank')">
                    </div>
                </div>
            </div>

            <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
                <label style="font-size:0.75rem; color:#888; font-weight:600; display:block; margin-bottom:5px;">STATUS
                    HISTORY</label>
                <div id="modalStatusHistory"
                    style="font-size:0.85rem; color:#555; background:#f9f9f9; padding:10px; border-radius:6px; border:1px solid #eee;">
                    No history available.
                </div>
            </div>

            <div id="modalActionArea"
                style="display:none; margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
                <div id="rejectReasonBox" style="display:none; margin-bottom: 15px;">
                    <label
                        style="font-size:0.75rem; color:#c0392b; font-weight:600; display:block; margin-bottom:5px;">REJECTION
                        REASON</label>
                    <textarea id="reviewRejectReason" rows="2" class="form-control"
                        placeholder="Please specify why the documents are rejected so the driver can fix them."></textarea>
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="btn btn-primary" id="btnApproveReview"
                        style="flex:1; padding: 12px; background: #2ecc71; border: none; font-weight: 600;"
                        onclick="reviewDriverAction('approve')" onchange="showGlobalLoader('Approving Documents...');">
                        <i class="fas fa-check-circle"></i> Approve Documents
                    </button>
                    <button class="btn btn-primary" id="btnRejectReview"
                        style="flex:1; padding: 12px; background: #e74c3c; border: none; font-weight: 600;"
                        onclick="reviewDriverAction('reject')" onchange="showGlobalLoader('Rejecting Documents...');">
                        <i class="fas fa-times-circle"></i> Reject Documents
                    </button>
                    <button class="btn btn-primary" id="btnConfirmReject"
                        style="display:none; flex:1; padding: 12px; background: #c0392b; border: none; font-weight: 600;"
                        onclick="submitRejectReview()">
                        <i class="fas fa-exclamation-triangle"></i> Confirm Reject
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="statusReasonModal" class="hr-modal-overlay" onclick="closeStatusModalEvent(event)" style="z-index: 1005;">
        <div class="hr-modal" style="max-width: 400px; padding: 25px;">
            <i class="fas fa-times hr-modal-close" onclick="closeStatusModal()"></i>
            <h3 id="statusModalTitle" style="margin-top: 0; color: #333;">Change Driver Status</h3>
            <p id="statusModalDesc" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-size:0.8rem; font-weight:600; color:#555; display:block; margin-bottom:8px;">Reason
                    for Action</label>
                <select id="statusReasonSelect" class="form-control" onchange="checkReasonSelection()"
                    style="width: 100%; padding: 10px; font-size: 0.95rem; border: 1px solid #ddd; border-radius: 8px;">
                    <option value="">-- Select a reason --</option>
                </select>
            </div>
            <button id="confirmStatusBtn" class="btn btn-primary" style="width:100%; padding: 12px; font-weight: 600;"
                disabled onclick="confirmStatusChange()">Confirm Change</button>
        </div>
    </div>

    <button id="backToTopBtn" onclick="window.scrollTo({top: 0, behavior: 'smooth'})"
        style="display:none; position:fixed; bottom:30px; right:30px; z-index:999; background:var(--primary-blue); color:white; border:none; width:50px; height:50px; border-radius:50%; box-shadow:0 4px 15px rgba(0,0,0,0.3); cursor:pointer; font-size:1.2rem; transition:0.3s;">
        <i class="fas fa-arrow-up"></i>
    </button>

    <script>const driverDataset = <?= json_encode($drivers) ?>;</script>
    <script src="manage_driver.js?v=<?= time() ?>"></script>
<?php include $depth . 'layout/admin_footer.php'; ?>