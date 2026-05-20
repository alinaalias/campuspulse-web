</div>
</div>
</div>


<script>
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

    // Global Loader JS logic
    function showGlobalLoader(customText = null) {
        if (customText) document.getElementById('loader-text').innerText = customText;
        document.getElementById('global-loader').style.display = 'flex';
    }
    function hideGlobalLoader() {
        document.getElementById('global-loader').style.display = 'none';
        document.getElementById('loader-text').innerText = 'Processing... Please wait.';
    }
    document.addEventListener('submit', function (e) {
        showGlobalLoader();
        const submitBtn = e.target.querySelector('button[type="submit"], input[type="submit"]');
        if (submitBtn) {
            setTimeout(() => {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            }, 10);
        }
    });
</script>

</body>

</html>