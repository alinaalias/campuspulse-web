/**
 * 1. Toggle Driver Status (Active/Inactive)
 */
function toggleStatus(checkbox, driverId) {
    // Determine the NEW status based on the checkbox state
    const newStatus = checkbox.checked ? 'active' : 'inactive';
    const actionName = checkbox.checked ? 'activate' : 'deactivate';

    // Confirmation Popup
    if (!confirm("Are you sure you want to " + actionName + " this driver?")) {
        // If user cancels, revert the checkbox immediately
        checkbox.checked = !checkbox.checked;
        return;
    }

    // Disable to prevent spam clicks while processing
    checkbox.disabled = true;

    // Send Request to Backend
    fetch('toggle_driver_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(driverId) + '&status=' + encodeURIComponent(newStatus)
    })
    .then(res => res.json())
    .then(data => {
        checkbox.disabled = false; // Re-enable checkbox
        
        if (data.success) {
            // Success! Reload page to update UI (e.g., delete button availability)
            location.reload();
        } else {
            // Failure
            alert("Error: " + (data.message || "Failed to update status"));
            checkbox.checked = !checkbox.checked; // Revert switch
        }
    })
    .catch(err => {
        console.error(err);
        alert("Network Error: Could not reach server.");
        checkbox.disabled = false;
        checkbox.checked = !checkbox.checked; // Revert switch
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

    // UI Feedback: Show spinner icon
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    saveBtn.disabled = true;
    select.disabled = true;

    console.log("Attempting to assign:", driverId, "to shuttle:", shuttleId);

    fetch('update_driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'driver_id=' + encodeURIComponent(driverId) + '&shuttle_id=' + encodeURIComponent(shuttleId)
    })
    .then(response => response.text()) // Get raw text first to debug PHP errors
    .then(text => {
        console.log("Server Response:", text);
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
            console.error("JSON Parse Error:", e, text);
            resetSaveButton(saveBtn, select);
        }
    })
    .catch(error => {
        alert("Network Error");
        console.error(error);
        resetSaveButton(saveBtn, select);
    });
}

/**
 * Helper to reset button state on error
 */
function resetSaveButton(btn, select) {
    btn.innerHTML = '<i class="fas fa-save"></i>';
    btn.disabled = false;
    select.disabled = false;
}