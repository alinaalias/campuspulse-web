<?php
session_start();
require_once '../../config.php';

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

/* =========================
   HANDLE ADD, EDIT, DELETE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $zoneId = trim($_POST['zone_id'] ?? '');
            if (!$zoneId)
                throw new Exception("Zone is required.");
            $capacity = intval($_POST['capacity'] ?? 13);

            $shuttleId = generateCustomId('shuttles', 'CPS', $firestore);
            $firestore->collection('Shuttles')->document($shuttleId)->set([
                'shuttle_id' => $shuttleId,
                'zone_id' => $zoneId,
                'capacity' => $capacity,
                'status' => 'active',
                'is_online' => false,
                'job_status' => 'idle',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            header('Location: shuttles_management.php?msg=added');
            exit();

        } elseif ($action === 'edit') {
            $shuttleId = $_POST['shuttle_id'] ?? '';
            $zoneId = trim($_POST['zone_id'] ?? '');
            $status = trim($_POST['status'] ?? '');
            $capacity = intval($_POST['capacity'] ?? 0);

            if (!$shuttleId || !$zoneId)
                throw new Exception("Missing required fields.");

            $updates = [
                ['path' => 'zone_id', 'value' => $zoneId],
                ['path' => 'capacity', 'value' => $capacity],
                ['path' => 'status', 'value' => $status],
                ['path' => 'updated_at', 'value' => date('Y-m-d H:i:s')]
            ];

            // CASCADING SAFETY: If we edit the bus to be anything other than active, force it offline
            if ($status !== 'active') {
                $updates[] = ['path' => 'is_online', 'value' => false];
                $updates[] = ['path' => 'job_status', 'value' => 'idle'];
            }

            $firestore->collection('Shuttles')->document($shuttleId)->update($updates);
            header('Location: shuttles_management.php?msg=updated');
            exit();

        } elseif ($action === 'delete') {
            $shuttleId = $_POST['shuttle_id'] ?? '';
            if ($shuttleId) {
                $firestore->collection('Shuttles')->document($shuttleId)->delete();
                header('Location: shuttles_management.php?msg=deleted');
                exit();
            }
        }
    } catch (Exception $e) {
        header('Location: shuttles_management.php?err=failed');
        exit();
    }
}

/* =========================
   FETCH ZONES (ID → NAME)
========================= */
$zoneMap = [];
$zonesSnapshot = $firestore->collection('Zones')->documents();
foreach ($zonesSnapshot as $z) {
    $zoneMap[$z->id()] = $z->data()['name'] ?? 'Unknown';
}

/* =========================
   FETCH SHUTTLES
========================= */
$shuttles = $firestore->collection('Shuttles')->documents();

/* =========================
   DRIVER REVERSE LOOKUP (Staffs)
========================= */
$assignedDrivers = [];
$staffsSnap = $firestore->collection('Staffs')->where('role', '=', 'driver')->documents();
foreach ($staffsSnap as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();
    $shuttleId = $d['assigned_shuttle_id'] ?? '';
    if (!empty($shuttleId)) {
        if (!isset($assignedDrivers[$shuttleId]))
            $assignedDrivers[$shuttleId] = [];
        $assignedDrivers[$shuttleId][] = explode(' ', trim($d['full_name']))[0];
    }
}
$today = date('Y-m-d');


$pageTitle = 'Shuttle Management - CampusPulse';
$depth = '../../';
include $depth . 'layout/admin/header.php';
?>

<style>
    .legend-card {
        background: #f0f4f8;
        border-left: 4px solid var(--primary-blue);
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        transition: all 0.3s ease;
    }

    .legend-title {
        margin: 0;
        font-size: 1rem;
        color: #2c3e50;
        font-weight: 700;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        user-select: none;
    }

    .legend-title-left {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .legend-grid {
        display: none;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        font-size: 0.85rem;
        color: #555;
        margin-top: 15px;
        border-top: 1px solid #d1d9e0;
        padding-top: 15px;
    }

    .legend-grid.show {
        display: grid;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Unified Row Hover Effect */
    table.styled-table tbody tr.searchable-row {
        transition: background-color 0.2s ease;
    }

    table.styled-table tbody tr.searchable-row:hover {
        background-color: #f8f9fa;
    }

    /* Pagination & Search styles */
    .admin-search-box {
        display: flex;
        align-items: center;
        background: #ffffff;
        border-radius: 8px;
        padding: 0 15px !important;
        width: 260px;
        height: 42px !important;
        border: 1px solid #cbd5e0;
        box-sizing: border-box !important;
        margin: 0 !important;
    }

    .admin-search-box i {
        color: #a0aec0;
        margin-right: 10px;
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    .admin-search-box input {
        border: none !important;
        background: transparent !important;
        outline: none !important;
        flex: 1 !important;
        min-width: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
        font-family: 'Poppins', sans-serif;
        font-size: 0.85rem;
        color: #2d3748;
        height: 100% !important;
    }

    .pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 15px;
        padding: 15px 0 5px 0;
        border-top: 1px solid #edf2f7;
    }

    .pagination-info {
        font-size: 0.85rem;
        color: #718096;
    }

    .pagination-buttons {
        display: flex;
        gap: 4px;
    }

    .page-btn {
        padding: 6px 12px;
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: 0.2s;
        color: #4a5568;
        font-weight: 500;
    }

    .page-btn:hover:not(:disabled) {
        background: #f7fafc;
        border-color: #cbd5e0;
    }

    .page-btn.active {
        background: var(--primary-blue);
        color: #fff;
        border-color: var(--primary-blue);
    }

    .page-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    /* Modal */
    .modal-overlay {
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

    .modal-content {
        background: white;
        width: 90%;
        max-width: 500px;
        border-radius: 12px;
        padding: 30px;
        position: relative;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        animation: fadeIn 0.2s ease;
    }
</style>


<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2 class="page-title">Shuttle Management</h2>
</div>

<div class="legend-card" id="quickGuideCard">
    <div class="legend-title" onclick="toggleLegend()">
        <div class="legend-title-left">
            <i class="fas fa-info-circle" style="color:var(--primary-blue);"></i>
            Quick Guide: Dashboard Legend
        </div>
        <i class="fas fa-chevron-down" id="legendIcon" style="color:#888; transition: transform 0.3s;"></i>
    </div>

    <div class="legend-grid" id="legendContent">
        <div class="legend-item">
            <strong><i class="fas fa-wrench"></i> Hardware Health (Status)</strong>
            <div><span class="legend-bullet" style="background:#2ecc71;"></span> <b>Active:</b> Vehicle
                is safe & operational.</div>
            <div><span class="legend-bullet" style="background:#f39c12;"></span> <b>Maintenance:</b> In
                workshop. Auto-dispatch disabled.</div>
            <div><span class="legend-bullet" style="background:#e74c3c;"></span> <b>Inactive:</b>
                Retired/Removed from active fleet.</div>
        </div>
        <div class="legend-item">
            <strong><i class="fas fa-user-tie"></i> Human Presence (Connection)</strong>
            <div><span class="legend-bullet" style="background:var(--success);"></span> <b>Live
                    Online:</b> A driver is currently inside and working.</div>
            <div><span class="legend-bullet" style="background:#999;"></span> <b>Parked / Offline:</b>
                The driver in a break / Shift is over, van is parked.</div>
        </div>
        <div class="legend-item">
            <strong><i class="fas fa-map-marked-alt"></i> Map Markers</strong>
            <div>Click any dot on the map to see the exact GPS coordinates and get a direct <b>Google
                    Maps Navigation link</b> to the vehicle's location.</div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 25px; padding: 0; overflow: hidden; border: 1px solid #e0e0e0;">
    <div
        style="padding: 15px 20px; background: #fafafa; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-boxes" style="color: var(--primary-blue); font-size: 1.2rem;"></i>
        <h3 style="margin: 0; font-size: 1.1rem; color: #333; font-weight: 600;">Inventory Map</h3>
    </div>
    <div id="fleetRadarMap"
        style="width: 100%; height: 400px; background: #eaebed; display: flex; justify-content: center; align-items: center;">
        <span style="color: #777;"><i class="fas fa-spinner fa-spin"></i> Initializing Map...</span>
    </div>
</div>

<?php if (isset($_GET['msg']) || isset($_GET['err'])): ?>
    <?php
    $msg = $_GET['msg'] ?? '';
    $err = $_GET['err'] ?? '';
    $displayText = '';

    switch ($msg) {
        case 'added':
            $displayText = "Shuttle successfully added!";
            break;
        case 'updated':
            $displayText = "Shuttle details updated.";
            break;
        case 'inactive':
            $displayText = "Shuttle deactivated successfully.";
            break;
        case 'deleted':
            $displayText = "Shuttle permanently deleted.";
            break;
    }

    if ($err === 'failed')
        $displayText = "Action failed. Please try again.";
    ?>
    <?php if ($displayText): ?>
        <div
            style="padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; 
                        <?php echo $err ? 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;' : 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; ?>">
            <i class="fas <?php echo $err ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($displayText); ?></span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:15px;">
        <h3 style="color:var(--primary-blue); margin:0;">Shuttles</h3>

        <div style="display:flex; gap:15px; align-items:center;">
            <div class="admin-search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchShuttles" placeholder="Search shuttles/zones..."
                    onkeyup="handleSearch('tableShuttles')">
            </div>
            <button class="btn btn-primary" onclick="openModal('add')"
                style="height:42px; margin:0; display:inline-flex; align-items:center; justify-content:center;">
                <i class="fas fa-plus"></i> Add Shuttle
            </button>
        </div>
    </div>
    <table class="styled-table" id="tableShuttles">
        <thead>
            <tr>
                <th>Shuttle ID</th>
                <th>Zone & Drivers</th>
                <th>Trips Today</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($shuttles->isEmpty()): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:20px; color:#777;">
                        No shuttles found. Click "Add Shuttle" to start.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($shuttles as $doc):
                    $s = $doc->data();
                    $zoneName = (!empty($s['zone_id']) && isset($zoneMap[$s['zone_id']])) ? $zoneMap[$s['zone_id']] : '<span style="color:#ccc">Unassigned</span>';
                    $driversList = $assignedDrivers[$doc->id()] ?? [];
                    $driverStr = empty($driversList) ? '<span style="color:#ccc">None</span>' : implode(', ', $driversList);

                    $tripsToday = 0;
                    try {
                        $query = $firestore->collection('Bookings')->where('shuttle_id', '=', $doc->id())->where('date', '=', $today);
                        if (method_exists($query, 'count')) {
                            $agg = $query->count();
                            if (is_int($agg)) {
                                $tripsToday = $agg;
                            } elseif (is_object($agg) && method_exists($agg, 'get')) {
                                $resArray = $agg->get();
                                foreach ($resArray as $res) {
                                    if (is_object($res) && method_exists($res, 'get')) {
                                        $tripsToday = $res->get('count') ?? 0;
                                    }
                                }
                            }
                        } else {
                            $tripsToday = "-";
                        }
                    } catch (Exception $e) {
                        $tripsToday = "-";
                    }
                    ?>
                    <tr class="searchable-row">
                        <td style="font-weight:600; color:var(--primary-blue);">
                            <i class="fas fa-van-shuttle"></i> <?= htmlspecialchars($doc->id()) ?>
                        </td>

                        <td>
                            <div style="font-weight:600; color:#333;"><?= $zoneName ?></div>
                            <div style="font-size:0.8rem; color:#888;"><i class="fas fa-id-badge"></i>
                                <?= $driverStr ?></div>
                        </td>

                        <td style="font-weight:bold; color:#555; text-align:center;">
                            <?= $tripsToday ?>
                        </td>

                        <td>
                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                <?php
                                $sStatus = $s['status'] ?? 'active';
                                $isOnline = $s['is_online'] ?? false;

                                if ($sStatus === 'maintenance') {
                                    echo '<span class="badge" style="background:#f39c12; color:white; border-radius:6px; font-size:0.7rem; padding:2px 8px; width:fit-content;"><i class="fas fa-tools"></i> MAINTENANCE</span>';
                                } elseif ($sStatus === 'inactive') {
                                    echo '<span class="badge" style="background:#e74c3c; color:white; border-radius:6px; font-size:0.7rem; padding:2px 8px; width:fit-content;"><i class="fas fa-ban"></i> INACTIVE</span>';
                                } else {
                                    echo '<span class="badge" style="background:#2ecc71; color:white; border-radius:6px; font-size:0.7rem; padding:2px 8px; width:fit-content;"><i class="fas fa-check-circle"></i> ACTIVE</span>';
                                }

                                if ($sStatus !== 'inactive') {
                                    if ($isOnline) {
                                        echo '<span style="font-size:0.75rem; color:var(--success); font-weight:600;"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Live Online</span>';
                                    } else {
                                        echo '<span style="font-size:0.75rem; color:#999; font-weight:600;"><i class="fas fa-circle" style="font-size:0.5rem;"></i> Parked / Offline</span>';
                                    }
                                }
                                ?>
                            </div>
                        </td>

                        <td>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <button class="btn"
                                    style="padding:5px 10px; font-size:0.8rem; border-radius:8px; background:#edf2f7; color:var(--primary-blue);"
                                    title="Edit Details"
                                    onclick="openModal('edit', '<?= $doc->id() ?>', '<?= $s['zone_id'] ?? '' ?>', <?= $s['capacity'] ?? 13 ?>, '<?= $s['status'] ?? 'active' ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <?php if ($sStatus !== 'maintenance' && $sStatus !== 'inactive'): ?>
                                    <form method="POST" action="process_shuttle_status.php" style="display:inline;">
                                        <input type="hidden" name="shuttle_id" value="<?= $doc->id() ?>">
                                        <input type="hidden" name="action" value="maintenance">
                                        <button type="submit" class="btn"
                                            style="background:#f39c12; color:white; padding:5px 10px; font-size:0.8rem; border:none; cursor:pointer; border-radius:8px;"
                                            onclick="return confirm('Send to Maintenance? This forces the vehicle offline and removes it from automated dispatch.')"
                                            title="Send to Maintenance">
                                            <i class="fas fa-tools"></i>
                                        </button>
                                    </form>
                                <?php elseif ($sStatus === 'maintenance'): ?>
                                    <form method="POST" action="process_shuttle_status.php" style="display:inline;">
                                        <input type="hidden" name="shuttle_id" value="<?= $doc->id() ?>">
                                        <input type="hidden" name="action" value="active">
                                        <button type="submit" class="btn"
                                            style="background:var(--success); color:white; padding:5px 10px; font-size:0.8rem; border:none; cursor:pointer; border-radius:8px;"
                                            onclick="return confirm('Return to Active fleet?')" title="Restore to Active">
                                            <i class="fas fa-check-double"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" style="display:inline;"
                                    onsubmit="return confirm('Are you sure you want to permanently delete this shuttle?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="shuttle_id" value="<?= $doc->id() ?>">
                                    <button type="submit" class="btn danger"
                                        style="padding:5px 10px; font-size:0.8rem; border:none; cursor:pointer; border-radius:8px;"
                                        title="Delete Shuttle">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div id="pagination-tableShuttles"></div>
</div>

</div>
</div>
</div>

<div class="modal-overlay" id="shuttleModalOverlay">
    <div class="modal-content">
        <button class="modal-close" type="button" onclick="closeModal()"
            style="position:absolute; top:15px; right:15px; border:none; background:none; font-size:1.2rem; cursor:pointer;"><i
                class="fas fa-times"></i></button>
        <h2 id="modalTitle" style="color:var(--primary-blue); margin-bottom:20px;">Add Shuttle</h2>

        <form method="POST" id="shuttleForm">
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="shuttle_id" id="modalShuttleId" value="">

            <div style="margin-bottom:15px;" id="shuttleIdDisplayGroup">
                <label style="font-weight:600;">Shuttle ID</label>
                <input type="text" id="shuttleIdDisplay" class="form-control" value="Auto-generated (CPSxxx)" disabled
                    style="background:#eee;">
            </div>

            <div style="margin-bottom:15px;">
                <label style="font-weight:600;">Assign Zone</label>
                <select name="zone_id" id="modalZoneId" class="form-control" required>
                    <option value="">-- Select Zone --</option>
                    <?php foreach ($zoneMap as $zid => $zname): ?>
                        <option value="<?= htmlspecialchars($zid) ?>"><?= htmlspecialchars($zname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:15px;" id="capacityGroup">
                <label style="font-weight:600;">Capacity (Seats)</label>
                <input type="number" name="capacity" id="modalCapacity" class="form-control" value="13" min="5" max="30"
                    required>
            </div>

            <div style="margin-bottom:25px; display:none;" id="statusGroup">
                <label style="font-weight:600;">Hardware Status</label>
                <select name="status" id="modalStatus" class="form-control">
                    <option value="active">Active (Healthy)</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="inactive">Inactive (Retired)</option>
                </select>
            </div>

            <div style="display:flex; justify-content:space-between;">
                <button type="button" class="btn" style="background:#eee; color:#333;"
                    onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Save Shuttle</button>
            </div>
        </form>
    </div>
</div>


<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>
<script>
    /* --- Modal & Table Logic --- */
    function openModal(mode, shuttleId = '', zoneId = '', capacity = 13, status = 'active') {
        const overlay = document.getElementById('shuttleModalOverlay');
        const title = document.getElementById('modalTitle');
        const actionInput = document.getElementById('modalAction');
        const idInput = document.getElementById('modalShuttleId');
        const idDisplay = document.getElementById('shuttleIdDisplay');
        const zoneSelect = document.getElementById('modalZoneId');
        const capInput = document.getElementById('modalCapacity');
        const statusSelect = document.getElementById('modalStatus');
        const statusGroup = document.getElementById('statusGroup');
        const submitBtn = document.getElementById('modalSubmitBtn');

        if (mode === 'add') {
            title.innerText = "Add New Shuttle";
            actionInput.value = "add";
            idInput.value = "";
            idDisplay.value = "Auto-generated (CPSxxx)";
            zoneSelect.value = "";
            capInput.value = "13";
            statusGroup.style.display = "none"; // Hide status on add
            submitBtn.innerText = "Add Shuttle";
        } else if (mode === 'edit') {
            title.innerText = "Edit Shuttle";
            actionInput.value = "edit";
            idInput.value = shuttleId;
            idDisplay.value = shuttleId;
            zoneSelect.value = zoneId;
            capInput.value = capacity;
            statusSelect.value = status;
            statusGroup.style.display = "block"; // Show status on edit
            submitBtn.innerText = "Update Shuttle";
        }

        overlay.style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('shuttleModalOverlay').style.display = 'none';
    }

    document.getElementById('shuttleModalOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    /* --- Pagination & Search --- */
    const ROWS_PER_PAGE = 5;

    document.addEventListener("DOMContentLoaded", () => {
        initTable('tableShuttles');
    });

    function initTable(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;
        table.dataset.currentPage = 1;
        renderTable(tableId);
    }

    function handleSearch(tableId) {
        const query = document.getElementById('searchShuttles').value.toLowerCase();
        const rows = document.querySelectorAll(`#${tableId} tbody tr.searchable-row`);

        rows.forEach(row => {
            const rowText = row.innerText.toLowerCase();
            if (rowText.includes(query)) {
                row.classList.remove('search-hidden');
            } else {
                row.classList.add('search-hidden');
            }
        });

        const table = document.getElementById(tableId);
        table.dataset.currentPage = 1;
        renderTable(tableId);
    }

    function renderTable(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;
        const currentPage = parseInt(table.dataset.currentPage);
        const visibleRows = Array.from(document.querySelectorAll(`#${tableId} tbody tr.searchable-row:not(.search-hidden)`));
        const totalRows = visibleRows.length;
        const totalPages = Math.ceil(totalRows / ROWS_PER_PAGE) || 1;

        const startIndex = (currentPage - 1) * ROWS_PER_PAGE;
        const endIndex = startIndex + ROWS_PER_PAGE;

        document.querySelectorAll(`#${tableId} tbody tr.searchable-row`).forEach(row => {
            row.style.display = 'none';
        });

        visibleRows.slice(startIndex, endIndex).forEach(row => {
            row.style.display = '';
        });

        buildPaginationUI(tableId, currentPage, totalPages, totalRows, startIndex, Math.min(endIndex, totalRows));
    }

    function buildPaginationUI(tableId, current, total, totalRows, start, end) {
        const container = document.getElementById(`pagination-${tableId}`);
        if (!container) return;
        if (totalRows === 0) {
            container.innerHTML = `<div class="pagination-container"><span class="pagination-info">No results found.</span></div>`;
            return;
        }

        let html = `<div class="pagination-container"><span class="pagination-info">Showing ${start + 1} to ${end} of ${totalRows} entries</span><div class="pagination-buttons"><button class="page-btn" ${current === 1 ? 'disabled' : ''} onclick="changePage('${tableId}', ${current - 1})"><i class="fas fa-chevron-left"></i></button>`;

        for (let i = 1; i <= total; i++) {
            html += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="changePage('${tableId}', ${i})">${i}</button>`;
        }

        html += `<button class="page-btn" ${current === total ? 'disabled' : ''} onclick="changePage('${tableId}', ${current + 1})"><i class="fas fa-chevron-right"></i></button></div></div>`;
        container.innerHTML = html;
    }

    function changePage(tableId, targetPage) {
        const table = document.getElementById(tableId);
        table.dataset.currentPage = targetPage;
        renderTable(tableId);
    }

    /* --- Map Logic --- */
    const zoneMap = <?= json_encode($zoneMap) ?>;
    let map;
    const fleetMarkers = {};
    let infoWindow;

    const firebaseConfig = {
        apiKey: "<?= MAPS_API_KEY ?>",
        authDomain: "<?= FIREBASE_AUTH_DOMAIN ?>",
        projectId: "<?= FIREBASE_PROJECT_ID ?>",
        storageBucket: "<?= FIREBASE_STORAGE_BUCKET ?>",
        messagingSenderId: "<?= FIREBASE_MESSAGING_SENDER_ID ?>",
        appId: "<?= FIREBASE_APP_ID ?>"
    };

    function toggleLegend() {
        const content = document.getElementById('legendContent');
        const icon = document.getElementById('legendIcon');
        content.classList.toggle('show');
        if (content.classList.contains('show')) icon.style.transform = "rotate(180deg)";
        else icon.style.transform = "rotate(0deg)";
    }

    async function initMap() {
        if (!document.getElementById("fleetRadarMap")) return;
        document.getElementById("fleetRadarMap").innerHTML = "";

        const defaultCenter = { lat: 3.1592, lng: 101.7036 };

        map = new google.maps.Map(document.getElementById("fleetRadarMap"), {
            center: defaultCenter,
            zoom: 14,
            mapId: "DEMO_MAP_ID",
            mapTypeControl: false,
            streetViewControl: false
        });

        infoWindow = new google.maps.InfoWindow();
        if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
        const db = firebase.firestore();

        db.collection('Shuttles').onSnapshot(snapshot => {
            const activeIds = new Set();

            snapshot.forEach(doc => {
                const data = doc.data();
                activeIds.add(doc.id);

                const lat = data.current_lat ?? null;
                const lng = data.current_lng ?? null;

                if (lat !== null && lng !== null) {
                    const pos = { lat, lng };
                    const zoneId = data.zone_id ?? '';
                    const zName = zoneMap[zoneId] || 'Unassigned';

                    const statusStr = data.status || 'active';
                    const isOnline = data.is_online || false;

                    let fillColor = '#9e9e9e';
                    let opacity = 0.6;
                    let badgeHtml = '<span style="background:#999; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">OFFLINE</span>';

                    if (statusStr === 'inactive') {
                        fillColor = '#e74c3c';
                        opacity = 0.4;
                        badgeHtml = '<span style="background:#e74c3c; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">INACTIVE</span>';
                    } else if (statusStr === 'maintenance') {
                        fillColor = '#f39c12';
                        opacity = 1.0;
                        badgeHtml = '<span style="background:#f39c12; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">MAINTENANCE</span>';
                    } else if (isOnline) {
                        fillColor = '#2ecc71';
                        opacity = 1.0;
                        badgeHtml = '<span style="background:#2ecc71; color:white; padding: 2px 6px; border-radius:10px; font-size:0.75rem;">ONLINE</span>';
                    }

                    if (!fleetMarkers[doc.id]) {
                        const markerElement = document.createElement('div');
                        markerElement.style.width = '16px';
                        markerElement.style.height = '16px';
                        markerElement.style.backgroundColor = fillColor;
                        markerElement.style.border = '2px solid #ffffff';
                        markerElement.style.borderRadius = '50%';
                        markerElement.style.opacity = opacity;
                        markerElement.style.boxShadow = '0 2px 4px rgba(0,0,0,0.3)';
                        markerElement.style.cursor = 'pointer';

                        const marker = new google.maps.marker.AdvancedMarkerElement({
                            map: map,
                            position: pos,
                            title: doc.id,
                            content: markerElement
                        });

                        markerElement.addEventListener('click', () => {
                            const navUrl = `https://www.google.com/maps/dir/?api=1&destination=$$${lat},${lng}`;

                            infoWindow.setContent(`
                                <div style="padding: 12px; font-family: 'Poppins', sans-serif; min-width: 200px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:8px;">
                                        <span style="font-weight:700; color:#2c3e50; font-size:1rem;"><i class="fas fa-bus"></i> ${doc.id}</span>
                                        ${badgeHtml}
                                    </div>
                                    
                                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 12px;">
                                        <i class="fas fa-map-marker-alt"></i> <b>Zone:</b> ${zName}<br>
                                        <i class="fas fa-compass"></i> <b>Coords:</b> ${lat.toFixed(4)}, ${lng.toFixed(4)}
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                        <a href="${navUrl}" target="_blank" style="text-decoration:none; background:#3498db; color:white; border-radius:6px; padding: 8px; font-size:0.75rem; text-align:center; font-weight:600;">
                                            <i class="fas fa-location-arrow"></i> Navigate
                                        </a>

                                        <form action="process_shuttle_status.php" method="POST" style="margin:0;">
                                            <input type="hidden" name="shuttle_id" value="${doc.id}">
                                            <input type="hidden" name="action" value="${statusStr === 'maintenance' ? 'active' : 'maintenance'}">
                                            <button type="submit" ${statusStr === 'inactive' ? 'disabled' : ''} style="width:100%; height:100%; background:${statusStr === 'maintenance' ? '#2ecc71' : (statusStr === 'inactive' ? '#ccc' : '#f39c12')}; color:white; border:none; border-radius:6px; padding: 8px; cursor:${statusStr === 'inactive' ? 'not-allowed' : 'pointer'}; font-size:0.75rem; font-family:inherit; font-weight:600;">
                                                <i class="fas ${statusStr === 'maintenance' ? 'fa-check' : 'fa-tools'}"></i> ${statusStr === 'maintenance' ? 'Restore' : 'Maint.'}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            `);
                            infoWindow.open(map, marker);
                        });
                        fleetMarkers[doc.id] = marker;

                        if (Object.keys(fleetMarkers).length === 1) {
                            map.setCenter(pos);
                        }
                    } else {
                        fleetMarkers[doc.id].position = pos;
                        fleetMarkers[doc.id].content.style.backgroundColor = fillColor;
                        fleetMarkers[doc.id].content.style.opacity = opacity;
                    }
                }
            });

            for (const id in fleetMarkers) {
                if (!activeIds.has(id)) {
                    fleetMarkers[id].map = null;
                    delete fleetMarkers[id];
                }
            }
        });
    }
</script>
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=<?= MAPS_API_KEY ?>&libraries=marker&callback=initMap&loading=async"></script>

<?php include $depth . 'layout/admin/footer.php'; ?>