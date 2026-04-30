/**
 * 1. Toggle Driver Status (Active/Inactive) via Reason Modal
 */
let pendingStatusDriverId = null;
let pendingStatus = null;
let pendingCheckbox = null;

function toggleStatus(checkbox, driverId) {
    pendingStatusDriverId = driverId;
    pendingStatus = checkbox.checked ? 'active' : 'inactive';
    pendingCheckbox = checkbox;

    const title = document.getElementById('statusModalTitle');
    const desc = document.getElementById('statusModalDesc');
    const select = document.getElementById('statusReasonSelect');

    if (pendingStatus === 'active') {
        title.innerText = 'Activate Driver';
        desc.innerText = 'Please provide a reason to activate this driver.';
        select.innerHTML = `
            <option value="">-- Select a reason --</option>
            <option value="Documents Verified">Documents Verified</option>
            <option value="Return from Leave">Return from Leave</option>
            <option value="Suspension Lifted">Suspension Lifted</option>
            <option value="Probation Passed">Probation Passed</option>
            <option value="Other">Other</option>
        `;
    } else {
        title.innerText = 'Deactivate Driver';
        desc.innerText = 'Please provide a reason for deactivating this driver.';
        select.innerHTML = `
            <option value="">-- Select a reason --</option>
            <option value="Disciplinary Suspension">Disciplinary Suspension</option>
            <option value="Medical Leave">Medical Leave</option>
            <option value="Resigned">Resigned</option>
            <option value="Credentials Verification Required">Credentials Verification Required</option>
            <option value="Other">Other</option>
        `;
    }

    document.getElementById('confirmStatusBtn').disabled = true;
    document.getElementById('statusReasonModal').style.display = 'flex';
}

function checkReasonSelection() {
    const select = document.getElementById('statusReasonSelect');
    document.getElementById('confirmStatusBtn').disabled = select.value === '';
}

function closeStatusModal() {
    document.getElementById('statusReasonModal').style.display = 'none';
    if (pendingCheckbox) {
        pendingCheckbox.checked = !pendingCheckbox.checked;
    }
    pendingStatusDriverId = null;
    pendingStatus = null;
    pendingCheckbox = null;
}

function closeStatusModalEvent(e) {
    if (e.target.id === 'statusReasonModal') closeStatusModal();
}

function confirmStatusChange() {
    const reason = document.getElementById('statusReasonSelect').value;
    const driverId = pendingStatusDriverId;
    const newStatus = pendingStatus;
    const btn = document.getElementById('confirmStatusBtn');

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    document.getElementById('statusReasonSelect').disabled = true;

    fetch('toggle_driver_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(driverId) + '&status=' + encodeURIComponent(newStatus) + '&reason=' + encodeURIComponent(reason)
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert("Error: " + (data.message || "Failed to update status"));
                closeStatusModal();
            }
        })
        .catch(err => {
            console.error(err);
            alert("Network Error: Could not reach server.");
            closeStatusModal();
        });
}

/**
 * 2. Enable Shuttle Assignment Dropdown
 */
function enableEdit(driverId) {
    document.getElementById('shuttle-' + driverId).disabled = false;
    document.getElementById('save-' + driverId).disabled = false;
}

/**
 * 3. Save Shuttle Assignment
 */
function saveAssignment(driverId) {
    const select = document.getElementById('shuttle-' + driverId);
    const saveBtn = document.getElementById('save-' + driverId);
    const shuttleId = select.value;

    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    saveBtn.disabled = true;
    select.disabled = true;

    fetch('update_driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'driver_id=' + encodeURIComponent(driverId) + '&shuttle_id=' + encodeURIComponent(shuttleId)
    })
        .then(response => response.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    alert("Success! Shuttle assigned.");
                    location.reload();
                } else {
                    alert("Error: " + (data.message || "Unknown error occurred"));
                    resetSaveButton(saveBtn, select);
                }
            } catch (e) {
                alert("Server Error: Invalid response format. Check console.");
                resetSaveButton(saveBtn, select);
            }
        })
        .catch(error => {
            alert("Network Error");
            resetSaveButton(saveBtn, select);
        });
}

function resetSaveButton(btn, select) {
    btn.innerHTML = '<i class="fas fa-save"></i>';
    btn.disabled = false;
    select.disabled = false;
}

/**
 * 4. Modal Profile UI Drivers Data Handling
 */
let currentReviewDriverId = null;

function openHrModal(driverId) {
    if (typeof driverDataset === 'undefined') {
        alert("System error: Driver dataset not loaded.");
        return;
    }

    currentReviewDriverId = driverId;
    const driver = driverDataset.find(d => d.id === driverId);
    if (!driver) return;

    document.getElementById('modalName').textContent = driver.full_name || 'N/A';
    document.getElementById('modalId').textContent = driver.id;

    const fallback = "https://cdn-icons-png.flaticon.com/512/149/149071.png";
    const noDocFallback = "https://via.placeholder.com/400x200?text=No+Document+Uploaded";

    document.getElementById('modalImg').src = driver.profile_pic_url || fallback;
    document.getElementById('modalLicImg').src = driver.license_pic_url || noDocFallback;
    document.getElementById('modalPsvImg').src = driver.psv_pic_url || noDocFallback;

    document.getElementById('modalIc').textContent = driver.ic_number || 'No data';
    document.getElementById('modalEmail').textContent = driver.email || 'No data';

    let gender = driver.gender || 'No data';
    if (gender !== 'No data') gender = gender.charAt(0).toUpperCase() + gender.slice(1);
    document.getElementById('modalGender').textContent = gender;

    document.getElementById('modalDob').textContent = driver.dob || 'No data';
    document.getElementById('modalAddress').textContent = driver.home_address || 'No data';
    document.getElementById('modalExp').textContent = (driver.years_experience ? driver.years_experience + ' years' : 'No data');

    // History Injection
    const historyEl = document.getElementById('modalStatusHistory');
    if (driver.last_status_change_reason) {
        const admin = driver.last_status_change_admin || 'Unknown Admin';
        const date = driver.last_status_change_at || 'Unknown Date';
        historyEl.innerHTML = `Last updated by <strong>${admin}</strong> on <strong>${date}</strong> for: <em>${driver.last_status_change_reason}</em>`;
    } else {
        historyEl.innerHTML = 'No history available.';
    }

    // Pending Review Control Injection
    const actionArea = document.getElementById('modalActionArea');
    if (actionArea) {
        if (driver.status === 'pending_review') {
            document.getElementById('rejectReasonBox').style.display = 'none';
            document.getElementById('btnApproveReview').style.display = 'block';
            document.getElementById('btnRejectReview').style.display = 'block';
            document.getElementById('btnConfirmReject').style.display = 'none';
            document.getElementById('reviewRejectReason').value = '';
            actionArea.style.display = 'block';
        } else {
            actionArea.style.display = 'none';
        }
    }

    document.getElementById('hrModal').style.display = 'flex';
}

function reviewDriverAction(actionType) {
    if (actionType === 'approve') {
        processDriverReview('approve', '');
    } else if (actionType === 'reject') {
        document.getElementById('btnApproveReview').style.display = 'none';
        document.getElementById('btnRejectReview').style.display = 'none';
        document.getElementById('rejectReasonBox').style.display = 'block';
        document.getElementById('btnConfirmReject').style.display = 'block';
    }
}

function submitRejectReview() {
    const reason = document.getElementById('reviewRejectReason').value.trim();
    if (!reason) {
        alert("Please provide a rejection reason.");
        return;
    }
    processDriverReview('reject', reason);
}

function processDriverReview(action, reason) {
    if (!currentReviewDriverId) return;

    fetch('process_driver_review.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(currentReviewDriverId) + '&action=' + encodeURIComponent(action) + '&reason=' + encodeURIComponent(reason)
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message || "Review processed successfully.");
                location.reload();
            } else {
                alert("Error: " + (data.message || "Failed to process review"));
            }
        })
        .catch(err => {
            alert("Network Error: Could not reach server.");
        });
}

function closeHrModal() {
    document.getElementById('hrModal').style.display = 'none';
}

function closeHrModalEvent(e) {
    if (e.target.id === 'hrModal') closeHrModal();
}

/**
 * 5. Dynamic Filtering & Pagination
 */
let currentFilter = 'all';
let displayedCount = 0;
const INITIAL_LOAD_COUNT = 10;
let cards = [];

document.addEventListener('DOMContentLoaded', () => {
    const cardNodeList = document.querySelectorAll('.driver-card');
    cards = Array.from(cardNodeList);
    displayedCount = INITIAL_LOAD_COUNT;
    applyPagination();
});

function applyPagination() {
    if (cards.length === 0) return;
    let visibleTotal = 0;

    cards.forEach(card => {
        let passesFilter = true;
        if (currentFilter === 'active' && card.dataset.active !== 'true') passesFilter = false;
        if (currentFilter === 'unassigned' && card.dataset.unassigned !== 'true') passesFilter = false;
        if (currentFilter === 'critical' && card.dataset.critical !== 'true') passesFilter = false;
        if (currentFilter === 'pending' && card.dataset.pending !== 'true') passesFilter = false;

        if (!passesFilter) {
            card.classList.add('hidden');
        } else {
            visibleTotal++;
            if (visibleTotal <= displayedCount) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        }
    });

    const loadBtn = document.getElementById('loadMoreContainer');
    const emptyMsgDiv = document.getElementById('filterEmptyMessage');
    const emptyTitle = document.getElementById('emptyMessageTitle');
    const emptyDesc = document.getElementById('emptyMessageDesc');

    if (!loadBtn) return;

    const countText = document.getElementById('loadedCountText');
    const buttonNode = loadBtn.querySelector('button');

    const totalMatching = cards.filter(c => {
        if (currentFilter === 'active') return c.dataset.active === 'true';
        if (currentFilter === 'unassigned') return c.dataset.unassigned === 'true';
        if (currentFilter === 'critical') return c.dataset.critical === 'true';
        if (currentFilter === 'pending') return c.dataset.pending === 'true';
        return true;
    }).length;

    if (totalMatching === 0) {
        if (emptyMsgDiv) {
            emptyMsgDiv.style.display = 'block';
            if (currentFilter === 'critical') {
                emptyTitle.innerText = "Excellent Compliance!";
                emptyDesc.innerText = "There are no drivers that require critical action today.";
            } else {
                emptyTitle.innerText = "No Results Found";
                emptyDesc.innerText = "No drivers match this filter.";
            }
        }
        loadBtn.style.display = 'none';
    } else {
        if (emptyMsgDiv) emptyMsgDiv.style.display = 'none';

        if (totalMatching > displayedCount) {
            loadBtn.style.display = 'block';
            if (buttonNode) buttonNode.style.display = 'inline-block';
            if (countText) countText.innerText = `Showing ${displayedCount} of ${totalMatching}`;
        } else {
            loadBtn.style.display = 'block';
            if (buttonNode) buttonNode.style.display = 'none';
            if (countText) countText.innerText = `Displaying all ${totalMatching} drivers.`;
        }
    }
}

function loadMoreCards() {
    displayedCount += INITIAL_LOAD_COUNT;
    applyPagination();
}

function toggleFilter(filterType) {
    document.querySelectorAll('.filter-card').forEach(f => f.classList.remove('active-filter'));

    if (currentFilter === filterType) {
        currentFilter = 'all';
    } else {
        currentFilter = filterType;
        const target = document.getElementById(`filter-${filterType}`);
        if (target) target.classList.add('active-filter');
    }

    displayedCount = INITIAL_LOAD_COUNT;
    applyPagination();
}

/**
 * 6. Back To Top Button Listener
 */
window.addEventListener('scroll', () => {
    const btn = document.getElementById('backToTopBtn');
    if (btn) {
        if (window.scrollY > 300) {
            btn.style.display = 'block';
        } else {
            btn.style.display = 'none';
        }
    }
});