document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const toggleBtn = document.getElementById('sidebarToggle');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('active');
            content.classList.toggle('active');
        });
    }
});