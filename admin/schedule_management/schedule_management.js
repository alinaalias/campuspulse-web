document.getElementById('zoneSelect').addEventListener('change', function () {
    loadRoutes(this.value);
    loadShuttles(this.value);
});

function loadRoutes(zoneId) {
    const route = document.getElementById('routeSelect');
    route.disabled = true;
    route.innerHTML = '<option>Loading...</option>';

    fetch(`fetch_routes.php?zone_id=${zoneId}`)
        .then(r => r.text())
        .then(html => {
            route.innerHTML = html;
            route.disabled = false;
        });
}

function loadShuttles(zoneId) {
    const container = document.getElementById('shuttleSelect');
    container.innerHTML = 'Loading...';

    fetch(`fetch_shuttles.php?zone_id=${zoneId}`)
        .then(r => r.text())
        .then(html => container.innerHTML = html);
}

function generateSchedule() {

    const date = document.getElementById('scheduleDate').value;
    const routeId = document.getElementById('routeSelect').value;
    const interval = document.getElementById('interval').value;

    const shuttles = [];
    document.querySelectorAll('input[name="shuttles[]"]:checked')
        .forEach(cb => shuttles.push(cb.value));

    const peaks = [];
    document.querySelectorAll('input[name="peak[]"]:checked')
        .forEach(cb => peaks.push(cb.value));

    const msg = document.getElementById('resultMsg');

    if (!date) {
        msg.innerText = '❌ Please select a date.';
        return;
    }

    if (!routeId) {
        msg.innerText = '❌ Please select a route.';
        return;
    }

    if (shuttles.length === 0) {
        msg.innerText = '❌ Please select at least one shuttle.';
        return;
    }

    if (peaks.length === 0) {
        document.getElementById('resultMsg').innerText = 'Please select at least one peak hour.';
        return;
    }

    msg.innerText = '⏳ Generating schedules...';

    const data = new URLSearchParams();
    data.append('date', date);
    data.append('route_id', routeId);
    data.append('interval', interval);

    shuttles.forEach(s => data.append('shuttles[]', s));
    peaks.forEach(p => data.append('peaks[]', p));

    fetch('auto_generate_schedule.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        msg.innerText = res.success
            ? `✅ ${res.count} schedules generated`
            : `❌ ${res.message}`;

        if (res.success) {
            setTimeout(() => location.reload(), 800);
        }
    })
    .catch(() => {
        msg.innerText = '❌ Server error occurred';
    });
}


function toggleAll(source) {
    document.querySelectorAll('input[name="ids[]"]').forEach(cb => {
        cb.checked = source.checked;
    });
}
