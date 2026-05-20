// Modal Tab Switcher
function switchModalTab(tabId, btnElement) {
    document.querySelectorAll('.modal-tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.modal-tab-btn').forEach(el => el.classList.remove('active'));

    document.getElementById(tabId).classList.add('active');
    btnElement.classList.add('active');
}

// Cascading Route Filter Logic
function updateRouteDropdown(zoneSelectId, dirSelectId, routeSelectId) {
    const zoneId = document.getElementById(zoneSelectId).value;
    const direction = document.getElementById(dirSelectId).value;
    const routeSelect = document.getElementById(routeSelectId);

    routeSelect.innerHTML = '<option value="">-- Select Route --</option>';

    if (!zoneId || !direction) {
        routeSelect.disabled = true;
        routeSelect.innerHTML = '<option value="">-- Filter Zone & Direction --</option>';
        return;
    }

    routeSelect.disabled = false;
    let found = false;

    // Filter routesData based on Zone AND Direction
    for (const [id, route] of Object.entries(routesData)) {
        if (route.zone_id === zoneId && route.direction === direction && route.status === 'active') {
            found = true;
            const option = document.createElement('option');
            option.value = id;
            option.textContent = route.route_name;
            routeSelect.appendChild(option);
        }
    }

    if (!found) {
        routeSelect.disabled = true;
        routeSelect.innerHTML = '<option value="">-- No Routes Available --</option>';
    }
}

function loadShuttles(zoneId, targetId) {
    const container = document.getElementById(targetId);
    if (!zoneId) {
        container.innerHTML = targetId === 'shuttleSelect' ? '<em style="color:#a0aec0; font-size:0.9rem;">Select a zone to load available shuttles...</em>' : '<option value="">-- Select Zone First --</option>';
        if (targetId !== 'shuttleSelect') container.disabled = true;
        return;
    }

    container.innerHTML = targetId === 'shuttleSelect' ? 'Loading...' : '<option value="">Loading...</option>';
    if (targetId !== 'shuttleSelect') container.disabled = false;

    const format = targetId === 'shuttleSelect' ? 'checkbox' : 'select';

    fetch(`fetch_shuttles.php?zone_id=${zoneId}&format=${format}`)
        .then(r => r.text())
        .then(html => container.innerHTML = html);
}

function generateSchedule() {

    const date = document.getElementById('scheduleDate').value;
    const direction = document.getElementById('directionSelect').value;
    const routeId = document.getElementById('routeSelect').value;
    const interval = document.getElementById('interval').value;

    const shuttles = [];
    document.querySelectorAll('input[name="shuttles[]"]:checked')
        .forEach(cb => shuttles.push(cb.value));

    const peaks = [];
    document.querySelectorAll('input[name="peak[]"]:checked')
        .forEach(cb => peaks.push(cb.value));

    const msg = document.getElementById('resultMsg');

    if (!date || !direction || !routeId) {
        msg.innerText = '❌ Please select date, direction, and route.';
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
    data.append('direction', direction);
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

function createSingleSchedule() {
    const date = document.getElementById('singleDate').value;
    const time = document.getElementById('singleTime').value;
    const direction = document.getElementById('singleDir').value;
    const zoneId = document.getElementById('singleZone').value;
    const routeId = document.getElementById('singleRoute').value;
    const shuttleId = document.getElementById('singleShuttle').value;

    const msg = document.getElementById('singleResultMsg');

    if (!date || !time || !direction || !zoneId || !routeId || !shuttleId) {
        msg.innerText = '❌ Please map out all fields carefully.';
        return;
    }

    msg.innerText = '⏳ Creating single schedule...';

    const data = new URLSearchParams();
    data.append('date', date);
    data.append('time', time);
    data.append('direction', direction);
    data.append('route_id', routeId);
    data.append('shuttle_id', shuttleId);

    fetch('create_single_schedule.php', {
        method: 'POST',
        body: data
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                msg.innerText = '✅ Schedule successfully created!';
                setTimeout(() => location.reload(), 800);
            } else {
                msg.innerText = `❌ ${res.message}`;
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
