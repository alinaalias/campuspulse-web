let pendingStudentId = null;
let pendingStudentStatus = null;
let pendingStudentCheckbox = null;

function toggleStudentStatus(checkbox, studentId) {
    // Intercept default behavior
    pendingStudentId = studentId;
    pendingStudentStatus = checkbox.checked ? 'active' : 'inactive';
    pendingStudentCheckbox = checkbox;

    const title = document.getElementById('studentStatusModalTitle');
    const desc = document.getElementById('studentStatusModalDesc');
    const select = document.getElementById('studentStatusReasonSelect');
    const card = document.getElementById('studentStatusModalCard');

    card.classList.remove('theme-activate', 'theme-deactivate');

    if (pendingStudentStatus === 'active') {
        title.innerText = 'Activate Student Account';
        desc.innerText = 'Select a reason for re-activating this student.';
        card.classList.add('theme-activate');
        select.innerHTML = `
            <option value="">-- Select a reason --</option>
            <option value="Re-enrolment">Re-enrolment</option>
            <option value="Suspension Lifted">Suspension Lifted</option>
            <option value="Financial Clearance">Financial Clearance</option>
            <option value="Correction of Error">Correction of Error</option>
            <option value="Other">Other</option>
        `;
    } else {
        title.innerText = 'Deactivate Student Account';
        desc.innerText = 'Select a reason for deactivating this student.';
        card.classList.add('theme-deactivate');
        select.innerHTML = `
            <option value="">-- Select a reason --</option>
            <option value="Graduated">Graduated</option>
            <option value="Suspended (Disciplinary)">Suspended (Disciplinary)</option>
            <option value="Financial Bar">Financial Bar</option>
            <option value="Withdrawn/Quit">Withdrawn/Quit</option>
            <option value="Academic Termination">Academic Termination</option>
            <option value="Other">Other</option>
        `;
    }

    document.getElementById('confirmStudentStatusBtn').disabled = true;
    document.getElementById('studentStatusReasonModal').style.display = 'flex';
}

function checkStudentReasonSelection() {
    const select = document.getElementById('studentStatusReasonSelect');
    document.getElementById('confirmStudentStatusBtn').disabled = select.value === '';
}

function closeStudentStatusModal() {
    document.getElementById('studentStatusReasonModal').style.display = 'none';
    if (pendingStudentCheckbox) {
        // Revert visually since cancelled
        pendingStudentCheckbox.checked = !pendingStudentCheckbox.checked;
    }
    pendingStudentId = null;
    pendingStudentStatus = null;
    pendingStudentCheckbox = null;
}

function closeStudentStatusModalEvent(e) {
    if (e.target.id === 'studentStatusReasonModal') closeStudentStatusModal();
}

function confirmStudentStatusChange() {
    const reason = document.getElementById('studentStatusReasonSelect').value;
    const studentId = pendingStudentId;
    const newStatus = pendingStudentStatus;
    const btn = document.getElementById('confirmStudentStatusBtn');

    // Prevent double-clicks
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled = true;
    document.getElementById('studentStatusReasonSelect').disabled = true;

    fetch('toggle_student_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(studentId) + '&status=' + encodeURIComponent(newStatus) + '&reason=' + encodeURIComponent(reason)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert("Error: " + (data.message || "Failed to update status"));
            closeStudentStatusModal();
        }
    })
    .catch(err => {
        console.error(err);
        alert("Network Error: Could not reach server.");
        closeStudentStatusModal();
    });
}