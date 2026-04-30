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

// --- CHECKBOX & DELETE BUTTON LOGIC ---
function updateDeleteButtonState(tableType) {
    const checkboxes = document.querySelectorAll(`.cb-${tableType}`);
    const deleteBtn = document.getElementById(`deleteBtn${tableType === 'active' ? 'Active' : 'Archived'}`);

    let isChecked = false;
    for (let cb of checkboxes) {
        if (cb.checked) {
            isChecked = true;
            break;
        }
    }
    deleteBtn.disabled = !isChecked;
}

function toggleAll(source, type) {
    const className = type === 'active' ? '.cb-active' : '.cb-archived';
    document.querySelectorAll(className).forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') {
            cb.checked = source.checked;
        }
    });
    updateDeleteButtonState(type);
}

document.addEventListener('change', function (e) {
    if (e.target.classList.contains('cb-active')) {
        updateDeleteButtonState('active');
    } else if (e.target.classList.contains('cb-archived')) {
        updateDeleteButtonState('archived');
    }
});

document.addEventListener("DOMContentLoaded", () => {
    updateDeleteButtonState('active');
    updateDeleteButtonState('archived');
});

// --- MODAL LOGIC ---
function openEtaModal(scheduleId) {
    const content = document.getElementById('eta-modal-' + scheduleId).innerHTML;
    document.getElementById('modalBodyData').innerHTML = content;
    document.getElementById('etaModalOverlay').style.display = 'flex';
}

function closeModal(event, overlayId) {
    if (event.target.id === overlayId) {
        document.getElementById(overlayId).style.display = 'none';
    }
}

// --- FILTER & PAGINATION LOGIC ---
const ROWS_PER_PAGE = 15;

document.addEventListener("DOMContentLoaded", () => {
    initTable('tableActive');
    initTable('tableArchived');
});

function initTable(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    table.dataset.currentPage = 1;
    renderTable(tableId);
}

function resetTableFilters(tableId) {
    const suffix = tableId === 'tableActive' ? 'Active' : 'Archived';
    document.getElementById(`filterDate${suffix}`).value = '';
    document.getElementById(`filterRoute${suffix}`).value = '';
    document.getElementById(`filterShuttle${suffix}`).value = '';
    applyFilters(tableId);
}

function applyFilters(tableId) {
    const suffix = tableId === 'tableActive' ? 'Active' : 'Archived';
    const dateVal = document.getElementById(`filterDate${suffix}`).value;
    const routeVal = document.getElementById(`filterRoute${suffix}`).value;
    const shuttleVal = document.getElementById(`filterShuttle${suffix}`).value;

    const rows = document.querySelectorAll(`#${tableId} tbody tr.searchable-row`);

    rows.forEach(row => {
        const rowDate = row.dataset.date || "";
        const rowRoute = row.dataset.route || "";
        const rowShuttle = row.dataset.shuttle || "";

        const matchDate = (dateVal === "" || rowDate === dateVal);
        const matchRoute = (routeVal === "" || rowRoute === routeVal);
        const matchShuttle = (shuttleVal === "" || rowShuttle === shuttleVal);

        if (matchDate && matchRoute && matchShuttle) {
            row.classList.remove('search-hidden');
        } else {
            row.classList.add('search-hidden');
            const cb = row.querySelector(`input[type="checkbox"]`);
            if (cb) cb.checked = false;
        }
    });

    updateDeleteButtonState(tableId === 'tableActive' ? 'active' : 'archived');

    const table = document.getElementById(tableId);
    table.dataset.currentPage = 1;
    renderTable(tableId);
}

function renderTable(tableId) {
    const table = document.getElementById(tableId);
    const currentPage = parseInt(table.dataset.currentPage);

    const visibleRows = Array.from(document.querySelectorAll(`#${tableId} tbody tr.searchable-row:not(.search-hidden)`));
    const totalRows = visibleRows.length;
    const totalPages = Math.ceil(totalRows / ROWS_PER_PAGE) || 1;

    const startIndex = (currentPage - 1) * ROWS_PER_PAGE;
    const endIndex = startIndex + ROWS_PER_PAGE;

    // Hide all rows
    document.querySelectorAll(`#${tableId} tbody tr.searchable-row`).forEach(row => {
        row.style.display = 'none';
    });

    // Show current page
    visibleRows.slice(startIndex, endIndex).forEach(row => {
        row.style.display = '';
    });

    buildPaginationUI(tableId, currentPage, totalPages, totalRows, startIndex, Math.min(endIndex, totalRows));
}

function buildPaginationUI(tableId, current, total, totalRows, start, end) {
    const container = document.getElementById(`pagination-${tableId}`);
    if (!container) return;

    if (totalRows === 0) {
        container.innerHTML = `<div class="pagination-container"><span class="pagination-info">No results found matching your filters.</span></div>`;
        return;
    }

    let html = `
                <div class="pagination-container">
                    <span class="pagination-info">Showing ${start + 1} to ${end} of ${totalRows} entries</span>
                    <div class="pagination-buttons">
                        <button type="button" class="page-btn" ${current === 1 ? 'disabled' : ''} onclick="changePage('${tableId}', ${current - 1})"><i class="fas fa-chevron-left"></i></button>
            `;

    let startPage = Math.max(1, current - 2);
    let endPage = Math.min(total, startPage + 4);
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }

    for (let i = startPage; i <= endPage; i++) {
        html += `<button type="button" class="page-btn ${i === current ? 'active' : ''}" onclick="changePage('${tableId}', ${i})">${i}</button>`;
    }

    html += `
                        <button type="button" class="page-btn" ${current === total ? 'disabled' : ''} onclick="changePage('${tableId}', ${current + 1})"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            `;
    container.innerHTML = html;
}

function changePage(tableId, targetPage) {
    const table = document.getElementById(tableId);
    table.dataset.currentPage = targetPage;
    renderTable(tableId);
}

function toggleAll(source) {
    document.querySelectorAll('input[name="ids[]"]').forEach(cb => {
        cb.checked = source.checked;
    });
}
