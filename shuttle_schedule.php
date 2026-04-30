<?php
require_once 'config.php';
session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;
// Set Timezone
date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');
$currentTime = date('H:i');

// 1. HANDLE FILTERS (DO NOT ALTER)
$inputDate = $_GET['date'] ?? '';
$filterZone = $_GET['zone_id'] ?? '';

if (!empty($inputDate)) {
    $startDate = $inputDate;
    $endDate = $inputDate;
} else {
    $startDate = $today;
    $endDate = date('Y-m-d', strtotime('+7 days'));
}

// 2. FETCHING HELPERS (With Cache)
function getCachedCollection($firestore, $collectionName, $keyField, $valueField = null)
{
    if (isset($_SESSION['cache_' . $collectionName]) && !empty($_SESSION['cache_' . $collectionName])) {
        return $_SESSION['cache_' . $collectionName];
    }
    $map = [];
    try {
        $docs = $firestore->database()->collection($collectionName)->documents();
        foreach ($docs as $doc) {
            $data = $doc->data();
            $id = $doc->id();
            if ($valueField) {
                $map[$id] = $data[$valueField] ?? 'Unknown';
            } else {
                $k = (!empty($keyField) && isset($data[$keyField])) ? $data[$keyField] : $id;
                $map[$k] = ($collectionName === 'Stops') ? ($data['name'] ?? 'Unknown') : $data;
            }
        }
        $_SESSION['cache_' . $collectionName] = $map;
    } catch (Exception $e) {
    }
    return $map;
}

$zonesMap = getCachedCollection($firestore, 'Zones', 'id', 'name');
$routesMap = getCachedCollection($firestore, 'Routes', 'id');
$stopsMap = getCachedCollection($firestore, 'Stops', 'stop_id');

// 3. FETCH SCHEDULES
$query = $firestore->database()->collection('Schedules')
    ->where('status', '=', 'published')
    ->where('date', '>=', $startDate)
    ->where('date', '<=', $endDate)
    ->orderBy('date', 'ASC')
    ->orderBy('departure_time', 'ASC');

try {
    $documents = $query->documents();
} catch (Exception $e) {
    $documents = [];
}

// 4. GROUP DATA — preserve capacity, booked_count, etas
$groupedSchedules = [];

if (!empty($documents)) {
    foreach ($documents as $doc) {
        $data = $doc->data();
        $rId = $data['route_id'];
        $schedDate = $data['date'];

        if (!isset($routesMap[$rId]))
            continue;
        $routeData = $routesMap[$rId];

        if (!empty($filterZone) && ($routeData['zone_id'] ?? '') !== $filterZone)
            continue;
        if ($schedDate === $today && $data['departure_time'] < $currentTime)
            continue;

        if (!isset($groupedSchedules[$rId])) {
            $groupedSchedules[$rId] = [
                'info' => $routeData,
                'dates' => []
            ];
        }

        // Store full schedule data including capacity fields and etas
        $groupedSchedules[$rId]['dates'][$schedDate][] = [
            'schedule_id' => $doc->id(),
            'departure_time' => $data['departure_time'],
            'capacity' => (int) ($data['capacity'] ?? 13),
            'booked_count' => (int) ($data['booked_count'] ?? 0),
            'etas' => $data['etas'] ?? [],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shuttle Schedules - CampusPulse</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">

    <style>
        /* ========================================
           SMART TRIP CHIP
        ======================================== */
        .trip-chip {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 10px;
            background: #f0f4ff;
            border: 1px solid #d0d9f0;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
            min-width: 90px;
            text-align: center;
            gap: 4px;
        }

        .trip-chip:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 18px rgba(0, 51, 102, 0.15);
            background: #e3ecff;
        }

        .trip-chip.highlight {
            background: #003366;
            border-color: #003366;
            color: white;
        }

        .trip-chip.highlight:hover {
            background: #004080;
        }

        .trip-chip.full-chip {
            background: #fff0f0;
            border-color: #f5c6c6;
            cursor: default;
        }

        .trip-chip.full-chip:hover {
            transform: none;
            box-shadow: none;
        }

        .chip-time {
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1;
        }

        .chip-seats {
            font-size: 0.68rem;
            font-weight: 600;
            color: #27ae60;
            white-space: nowrap;
        }

        .trip-chip.highlight .chip-seats {
            color: rgba(255, 255, 255, 0.8);
        }

        .chip-full-text {
            font-size: 0.68rem;
            font-weight: 700;
            color: #e74c3c;
            letter-spacing: 0.04em;
        }

        /* ========================================
           ETA MODAL
        ======================================== */
        .eta-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .eta-modal-card {
            background: white;
            border-radius: 16px;
            padding: 28px 30px 24px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: etaSlideUp 0.28s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes etaSlideUp {
            from {
                transform: translateY(24px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .eta-modal-close {
            position: absolute;
            top: 16px;
            right: 18px;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #aaa;
            line-height: 1;
            padding: 4px;
        }

        .eta-modal-close:hover {
            color: #333;
        }

        .eta-modal-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #003366;
            margin: 0 0 4px 0;
        }

        .eta-modal-subtitle {
            font-size: 0.82rem;
            color: #888;
            margin: 0 0 22px 0;
        }

        /* Vertical Timeline */
        .eta-timeline {
            position: relative;
            padding-left: 24px;
        }

        .eta-timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: linear-gradient(to bottom, #003366, #cdd6f4);
            border-radius: 2px;
        }

        .eta-stop {
            position: relative;
            padding: 0 0 20px 20px;
        }

        .eta-stop:last-child {
            padding-bottom: 0;
        }

        .eta-stop::before {
            content: '';
            position: absolute;
            left: -2px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: white;
            border: 2.5px solid #003366;
        }

        .eta-stop:first-child::before {
            background: #003366;
        }

        .eta-stop:last-child::before {
            background: #e74c3c;
            border-color: #e74c3c;
        }

        .eta-stop-time {
            font-size: 0.75rem;
            font-weight: 700;
            color: #003366;
            letter-spacing: 0.04em;
            line-height: 1;
            margin-bottom: 2px;
        }

        .eta-stop-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: #2c3e50;
            line-height: 1.3;
        }

        .eta-stop:first-child .eta-stop-name {
            color: #003366;
            font-weight: 700;
        }

        .eta-stop:last-child .eta-stop-name {
            color: #e74c3c;
            font-weight: 700;
        }

        /* Capacity band inside modal */
        .eta-capacity-bar {
            margin-bottom: 20px;
            background: #f4f7ff;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .cap-icon {
            font-size: 1.2rem;
        }

        .cap-bar-wrap {
            flex: 1;
        }

        .cap-bar-track {
            height: 6px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .cap-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.4s ease;
        }

        .cap-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #555;
        }
    </style>
</head>

<body class="schedule-body">

    <?php include 'layout/public_header.php'; ?>

    <div class="search-header">
        <h1>Find Your Shuttle</h1>
        <p>Check real-time schedules for all campus zones</p>
    </div>

    <form method="GET" class="search-container">
        <div class="filter-group">
            <label class="filter-label"><i class="far fa-calendar-alt"></i> Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($inputDate) ?>" class="form-control-custom">
        </div>

        <div class="filter-group">
            <label class="filter-label"><i class="fas fa-map-marker-alt"></i> Zone</label>
            <select name="zone_id" class="form-control-custom">
                <option value="">All Zones</option>
                <?php foreach ($zonesMap as $zId => $zName): ?>
                    <option value="<?= $zId ?>" <?= $filterZone === $zId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($zName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn-filter">
            <i class="fas fa-filter"></i> Apply
        </button>

        <a href="shuttle_schedule.php" class="btn-reset">
            <i class="fas fa-undo"></i> Reset
        </a>
    </form>

    <div class="route-grid">
        <?php if (empty($groupedSchedules)): ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 60px;">
                <i class="fas fa-bus" style="font-size: 3rem; color: #ddd; margin-bottom: 20px;"></i>
                <h3 style="color:#555;">No schedules found</h3>
                <p style="color:#999;">Try selecting a different date or zone.</p>
            </div>
        <?php else: ?>
            <?php foreach ($groupedSchedules as $rId => $group):
                $routeInfo = $group['info'];
                $startName = $stopsMap[$routeInfo['start_stop_id']] ?? 'Start';
                $endName = $stopsMap[$routeInfo['end_stop_id']] ?? 'End';
                $zId = $routeInfo['zone_id'] ?? '';
                $zName = $zonesMap[$zId] ?? 'General';
                ?>

                <div class="route-card">

                    <div class="card-header-clean">
                        <span class="zone-badge"><?= htmlspecialchars($zName) ?></span>
                        <div class="live-status">
                            <div class="live-dot"></div> Active
                        </div>
                    </div>

                    <div class="card-main-info">
                        <div class="route-title"><?= htmlspecialchars($routeInfo['route_name'] ?? $rId) ?></div>

                        <div class="route-path-container">
                            <span style="font-weight:600;"><?= htmlspecialchars($startName) ?></span>
                            <i class="fas fa-arrow-right path-arrow"></i>
                            <span style="font-weight:600;"><?= htmlspecialchars($endName) ?></span>
                        </div>
                    </div>

                    <div class="card-schedule">
                        <?php foreach ($group['dates'] as $dateStr => $trips):
                            $label = ($dateStr == $today) ? "Today" : date('D, d M', strtotime($dateStr));
                            $isToday = ($dateStr == $today);
                            ?>
                            <div class="date-row">
                                <div class="date-header <?= $isToday ? 'today' : '' ?>">
                                    <?= $label ?>
                                </div>
                                <div class="time-grid">
                                    <?php foreach ($trips as $trip):
                                        $timeStr = date('h:i A', strtotime($trip['departure_time']));
                                        $capacity = $trip['capacity'];
                                        $booked = $trip['booked_count'];
                                        $seatsLeft = $capacity - $booked;
                                        $isFull = ($seatsLeft <= 0);

                                        // Safely encode etas for a data attribute
                                        $etasJson = htmlspecialchars(json_encode($trip['etas'] ?? []), ENT_QUOTES, 'UTF-8');
                                        $schedId = htmlspecialchars($trip['schedule_id']);
                                        $routeName = htmlspecialchars($routeInfo['route_name'] ?? $rId);
                                        ?>
                                        <div class="trip-chip <?= $isToday ? 'highlight' : '' ?> <?= $isFull ? 'full-chip' : 'clickable-chip' ?>"
                                            data-sched-id="<?= $schedId ?>" data-route-name="<?= $routeName ?>"
                                            data-time="<?= $timeStr ?>" data-capacity="<?= $capacity ?>" data-booked="<?= $booked ?>"
                                            data-etas="<?= $etasJson ?>">

                                            <span class="chip-time"><?= $timeStr ?></span>
                                            <?php if ($isFull): ?>
                                                <span class="chip-full-text">FULL</span>
                                            <?php else: ?>
                                                <span class="chip-seats"><?= $seatsLeft ?> seat<?= $seatsLeft !== 1 ? 's' : '' ?>
                                                    left</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="card-footer-cta">
                        <i class="fas fa-mobile-alt"></i>
                        <strong>Book a ride via CampusPulse App</strong>
                    </div>
                </div>

            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ========================================
     ETA TIMELINE MODAL
======================================== -->
    <div id="etaModal" class="eta-modal-overlay" onclick="closeEtaModal(event)">
        <div class="eta-modal-card">
            <button class="eta-modal-close" onclick="document.getElementById('etaModal').style.display='none'">
                <i class="fas fa-times"></i>
            </button>

            <p class="eta-modal-title" id="etaModalRoute">Route Name</p>
            <p class="eta-modal-subtitle" id="etaModalDep">Departing at —</p>

            <!-- Capacity Bar -->
            <div class="eta-capacity-bar">
                <span class="cap-icon">🪑</span>
                <div class="cap-bar-wrap">
                    <div class="cap-label" id="etaCapLabel">Loading...</div>
                    <div class="cap-bar-track">
                        <div class="cap-bar-fill" id="etaCapFill" style="width:0%; background:#27ae60;"></div>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="eta-timeline" id="etaTimeline">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <!-- Pass stopsMap to JavaScript -->
    <script>
        const STOPS_MAP = <?= json_encode($stopsMap, JSON_HEX_TAG) ?>;

        // Attach click listeners to all valid trip chips
        document.addEventListener('DOMContentLoaded', () => {
            const chips = document.querySelectorAll('.clickable-chip');
            chips.forEach(chip => {
                chip.addEventListener('click', function () {
                    const schedId = this.dataset.schedId;
                    const routeName = this.dataset.routeName;
                    const depTime = this.dataset.time;
                    const capacity = parseInt(this.dataset.capacity);
                    const booked = parseInt(this.dataset.booked);
                    const etas = JSON.parse(this.dataset.etas || '{}');

                    openEtaModal(schedId, routeName, depTime, capacity, booked, etas);
                });
            });
        });

        function openEtaModal(schedId, routeName, depTime, capacity, booked, etas) {
            // Header
            document.getElementById('etaModalRoute').textContent = routeName;
            document.getElementById('etaModalDep').textContent = 'Departing at ' + depTime;

            // Capacity bar
            const seatsLeft = capacity - booked;
            const fillPct = Math.min(100, Math.round((booked / Math.max(capacity, 1)) * 100));
            const barColor = fillPct >= 100 ? '#e74c3c' : (fillPct >= 75 ? '#f39c12' : '#27ae60');
            const fill = document.getElementById('etaCapFill');
            const capLabel = document.getElementById('etaCapLabel');

            fill.style.width = fillPct + '%';
            fill.style.background = barColor;
            capLabel.textContent = seatsLeft > 0
                ? seatsLeft + ' seat' + (seatsLeft !== 1 ? 's' : '') + ' available of ' + capacity
                : 'This trip is FULL';

            // Timeline
            const timeline = document.getElementById('etaTimeline');
            timeline.innerHTML = '';

            // Build sorted array: [{stop_id, time}, ...]
            const stops = Object.entries(etas).map(([stopId, time]) => ({
                stop_id: stopId,
                time: time
            }));

            // Sort chronologically by HH:MM string
            stops.sort((a, b) => a.time.localeCompare(b.time));

            if (stops.length === 0) {
                timeline.innerHTML = '<p style="color:#aaa; font-size:0.85rem; text-align:center; padding: 10px 0;">No stop-by-stop ETA data available for this trip.</p>';
            } else {
                stops.forEach(entry => {
                    const stopName = STOPS_MAP[entry.stop_id] || entry.stop_id;

                    // Format time from HH:MM to h:MM AM/PM
                    const [hh, mm] = entry.time.split(':').map(Number);
                    const ampm = hh >= 12 ? 'PM' : 'AM';
                    const h12 = ((hh % 12) || 12);
                    const formatted = h12 + ':' + String(mm).padStart(2, '0') + ' ' + ampm;

                    const div = document.createElement('div');
                    div.className = 'eta-stop';
                    div.innerHTML = `
                    <div class="eta-stop-time">${formatted}</div>
                    <div class="eta-stop-name">${stopName}</div>
                `;
                    timeline.appendChild(div);
                });
            }

            // Show Modal
            document.getElementById('etaModal').style.display = 'flex';
        }

        function closeEtaModal(event) {
            if (event.target.id === 'etaModal') {
                document.getElementById('etaModal').style.display = 'none';
            }
        }
    </script>

    <?php include 'layout/footer.php'; ?>

</body>

</html>