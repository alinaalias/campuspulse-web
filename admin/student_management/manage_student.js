function toggleStudentStatus(checkbox, studentId) {
    // 1. Determine the NEW status based on the checkbox state
    // If checked, we want to make it 'active'. If unchecked, 'inactive'.
    const newStatus = checkbox.checked ? 'active' : 'inactive';
    const actionName = checkbox.checked ? 'activate' : 'deactivate';

    // 2. Confirmation Popup
    if (!confirm("Are you sure you want to " + actionName + " this student account?")) {
        // If user cancels, revert the checkbox immediately to its previous state
        checkbox.checked = !checkbox.checked;
        return;
    }

    // 3. Disable to prevent double-clicking while processing
    checkbox.disabled = true;

    // 4. Send Request
    fetch('toggle_student_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(studentId) + '&status=' + encodeURIComponent(newStatus)
    })
    .then(res => res.json())
    .then(data => {
        checkbox.disabled = false; // Re-enable
        
        if (data.success) {
            // Success! Reload to update the UI (especially the delete button state)
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