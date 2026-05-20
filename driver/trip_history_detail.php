<?php
session_start();
require_once '../config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

$tripId = $_GET['id'] ?? '';
$tripType = $_GET['type'] ?? ''; // 'schedule' or 'ondemand'

// Strip prefixes to ensure the Firestore query always succeeds
$tripId = str_replace(['BOOK:', 'SCHED:'], '', $tripId);

if (!$tripId || !in_array($tripType, ['schedule', 'ondemand'])) {
    $_SESSION['error'] = "Invalid trip details requested.";
    header('Location: driver_trip_history.php');
    exit();
}

function parseLocalTime($rawTime)
{
    if (empty($rawTime))
        return 'Unknown Time';
    try {
        if (is_object($rawTime) && method_exists($rawTime, 'get')) {
            $dt = clone $rawTime->get();
            $dt->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
            return $dt->format('Y-m-d H:i:s');
        } elseif (!empty($rawTime) && !is_numeric($rawTime)) {
            $dt = new DateTime((string) $rawTime);
            return $dt->format('Y-m-d H:i:s');
        } elseif (is_numeric($rawTime)) {
            $dt = new DateTime('@' . $rawTime);
            $dt->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'));
            return $dt->format('Y-m-d H:i:s');
        }
    } catch (Exception $e) {
    }
    return 'Unknown Time';
}

function getPassengerLogTime($logs, $studentName, $eventType)
{
    if (empty($logs) || empty($studentName) || empty($eventType)) {
        return 'Unknown Time';
    }

    foreach ($logs as $log) {
        $logType = strtolower($log['type'] ?? '');

        if ($logType !== strtolower($eventType)) {
            continue;
        }

        $message = strip_tags($log['message'] ?? '');

        if (stripos($message, $studentName) !== false) {
            return parseLocalTime($log['timestamp'] ?? null);
        }
    }

    return 'Unknown Time';
}

$tripData = null;
$passengerLog = [];
$routeDetails = [];
$bookingIdsArray = [];

$isCompleted = isset($_GET['completed']) && $_GET['completed'] === 'true';
$totalFare = 0;
$ratingAvg = 0;
$ratingList = [];
$tripLogs = [];

try {
    $db = $firestore->database();

    // SCHEDULED TRIPS
    if ($tripType === 'schedule') {
        $schedSnap = $db->collection('Schedules')->document($tripId)->snapshot();
        if (!$schedSnap->exists() || $schedSnap->data()['driver_id'] !== $driverId) {
            throw new Exception("Schedule not found or unauthorized.");
        }
        $tripData = $schedSnap->data();
        $tripData['id'] = $schedSnap->id();

        $routeName = "Unknown Route";
        if (!empty($tripData['route_id'])) {
            $rSnap = $db->collection('Routes')->document($tripData['route_id'])->snapshot();
            if ($rSnap->exists()) {
                $routeDetails = $rSnap->data();
                $routeName = $routeDetails['route_name'];
            }
        }
        $tripData['display_title'] = $routeName;
        $tripData['display_icon'] = 'fa-bus';
        $tripData['display_type'] = 'Scheduled Shuttle Route';

        $bookingsQuery = $db->collection('Bookings')
            ->where('schedule_id', '=', $tripId)
            ->where('status', 'in', ['onboard', 'completed'])
            ->documents();

        foreach ($bookingsQuery as $bDoc) {
            $bData = $bDoc->data();
            $bookingIdsArray[] = $bDoc->id(); // Store ID for ratings
            $totalFare += (float) ($bData['fare'] ?? 0);

            $studentName = "Unknown Student";
            $studentId = $bData['user_id'] ?? ($bData['student_id'] ?? '');
            if ($studentId) {
                $stSnap = $db->collection('Students')->document($studentId)->snapshot();
                if ($stSnap->exists()) {
                    $studentName = $stSnap->data()['full_name'] ?? 'Student';
                }
            }

            $pickupName = $bData['pickup_stop_id'] ?? 'Unknown Stop';
            if ($pickupName) {
                $pSnap = $db->collection('Stops')->document($pickupName)->snapshot();
                if ($pSnap->exists())
                    $pickupName = $pSnap->data()['name'] ?? ($pSnap->data()['stop_name'] ?? $pickupName);
            }

            $checkInTime = getPassengerLogTime(
                $tripData['trip_logs'] ?? [],
                $studentName,
                'in'
            );

            $checkOutTime = getPassengerLogTime(
                $tripData['trip_logs'] ?? [],
                $studentName,
                'out'
            );

            $passengerLog[] = [
                'name' => $studentName,
                'pickup' => $pickupName,
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
                'status' => ucfirst($bData['status'] ?? 'Boarded')
            ];
        }

        usort($passengerLog, function ($a, $b) {
            if ($a['check_in_time'] === 'Unknown Time')
                return 1;
            if ($b['check_in_time'] === 'Unknown Time')
                return -1;
            return strtotime($a['check_in_time']) - strtotime($b['check_in_time']);
        });

    }
    // ON-DEMAND TRIPS
    else if ($tripType === 'ondemand') {
        $bookingSnap = $db->collection('Bookings')->document($tripId)->snapshot();
        if (!$bookingSnap->exists() || $bookingSnap->data()['driver_id'] !== $driverId) {
            throw new Exception("On-Demand record not found or unauthorized.");
        }
        $bData = $bookingSnap->data();
        $bookingIdsArray[] = $bookingSnap->id(); // Store ID for ratings
        $totalFare = (float) ($bData['fare'] ?? 0);

        $tripData = $bData;
        $tripData['id'] = $bookingSnap->id();
        $tripData['display_title'] = "On-Demand Ride";
        $tripData['display_icon'] = 'fa-car';
        $tripData['display_type'] = 'Point-to-Point Transit';

        $shuttleId = $bData['shuttle_id'] ?? '';
        if (empty($shuttleId)) {
            $driverSnap = $db->collection('Staffs')->document($driverId)->snapshot();
            if ($driverSnap->exists()) {
                $shuttleId = $driverSnap->data()['assigned_shuttle_id'] ?? 'Unknown Vehicle';
            }
        }
        $tripData['shuttle_id'] = $shuttleId;

        $rawTs = $bData['completed_at'] ?? $bData['updated_at'] ?? $bData['created_at'] ?? time();
        if (is_object($rawTs) && method_exists($rawTs, 'get')) {
            $ts = $rawTs->get()->format('U');
        } elseif (!is_numeric($rawTs)) {
            $ts = strtotime($rawTs);
        } else {
            $ts = $rawTs;
        }

        $tripData['date'] = date('Y-m-d', $ts);
        $tripData['departure_time'] = date('H:i', $ts);

        $pId = $bData['pickup_stop_id'] ?? '';
        $dId = $bData['dropoff_stop_id'] ?? '';

        $pickupName = "Current Location";
        if ($pId) {
            $pSnap = $db->collection('Stops')->document($pId)->snapshot();
            if ($pSnap->exists())
                $pickupName = $pSnap->data()['name'] ?? ($pSnap->data()['stop_name'] ?? $pId);
        }

        $dropoffName = "Destination";
        if ($dId) {
            $dSnap = $db->collection('Stops')->document($dId)->snapshot();
            if ($dSnap->exists())
                $dropoffName = $dSnap->data()['name'] ?? ($dSnap->data()['stop_name'] ?? $dId);
        }

        $studentName = "Unknown Student";
        $studentId = $bData['user_id'] ?? ($bData['student_id'] ?? '');
        if ($studentId) {
            $stSnap = $db->collection('Students')->document($studentId)->snapshot();
            if ($stSnap->exists()) {
                $studentName = $stSnap->data()['full_name'] ?? 'Student';
            }
        }

        $checkInTime = getPassengerLogTime(
            $tripData['trip_logs'] ?? [],
            $studentName,
            'in'
        );

        $checkOutTime = getPassengerLogTime(
            $tripData['trip_logs'] ?? [],
            $studentName,
            'out'
        );

        $passengerLog[] = [
            'name' => $studentName,
            'pickup' => $pickupName,
            'dropoff' => $dropoffName,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
            'status' => ucfirst($bData['status'] ?? 'Completed')
        ];
    }

} catch (Exception $e) {
    $_SESSION['error'] = "Error loading trip details: " . $e->getMessage();
    header('Location: driver_trip_history.php');
    exit();
}

$tripLogs = $tripData['trip_logs'] ?? [];
if (!empty($tripLogs)) {
    foreach ($tripLogs as &$log) {
        $log['timestamp'] = parseLocalTime($log['timestamp'] ?? null);
    }
    unset($log);

    usort($tripLogs, function ($a, $b) {
        $timeA = isset($a['timestamp']) && $a['timestamp'] !== 'Unknown Time' ? strtotime($a['timestamp']) : 0;
        $timeB = isset($b['timestamp']) && $b['timestamp'] !== 'Unknown Time' ? strtotime($b['timestamp']) : 0;
        return $timeA - $timeB;
    });
}

// THE FIX 1: Fetch Initial Ratings by looping through the collected Booking IDs
$totalRating = 0;
if (!empty($bookingIdsArray)) {
    $chunks = array_chunk($bookingIdsArray, 10);
    foreach ($chunks as $chunk) {
        $ratingsQuery = $db->collection('Ratings')->where('booking_id', 'in', $chunk)->documents();
        foreach ($ratingsQuery as $doc) {
            if ($doc->exists()) {
                $d = $doc->data();
                $ratingList[] = $d;
                $totalRating += (float) ($d['rating'] ?? 0);
            }
        }
    }
}
$ratingAvg = count($ratingList) > 0 ? round($totalRating / count($ratingList), 1) : 0;

$statusLower = strtolower($tripData['status'] ?? 'unknown');

if ($tripType === 'schedule') {
    $tripTimestamp = strtotime(($tripData['date'] ?? '') . ' ' . ($tripData['departure_time'] ?? ''));
    if ($tripTimestamp && (time() > ($tripTimestamp + 900))) {
        if ($statusLower !== 'completed' && $statusLower !== 'cancelled') {
            $statusLower = 'missed';
            $tripData['status'] = 'missed';
        }
    }
}

$statusClass = 'status-cancelled';
if ($statusLower === 'completed')
    $statusClass = 'status-completed';
elseif ($statusLower === 'missed')
    $statusClass = 'status-missed';

$pageTitle = 'Trip Summary';
$hideNavbar = true;
$extraHead = '
<style>
    .summary-header { background: white; padding: 25px 20px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); margin-bottom: 20px; text-align: center; }
    .icon-circle { width: 60px; height: 60px; background: #f0f4f8; color: var(--primary-blue); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 15px; }
    .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px; }
    .stat-box { text-align: center; }
    .stat-box .label { font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
    .stat-box .value { font-size: 1.1rem; font-weight: 700; color: #333; }
    .passenger-list { background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); overflow: hidden; margin-bottom: 20px; }
    .list-header { padding: 15px 20px; background: #fafafa; border-bottom: 1px solid #eee; font-weight: 600; color: #555; display: flex; justify-content: space-between; align-items: center; }
    .passenger-item { padding: 15px 20px; border-bottom: 1px solid #f5f5f5; display: flex; align-items: flex-start; gap: 15px; }
    .passenger-item:last-child { border-bottom: none; }
    .avatar-placeholder { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1.1rem; flex-shrink: 0; }
    .status-completed { color: #2ecc71; background: #eafaf1; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .status-cancelled { color: #e74c3c; background: #fdedec; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .status-missed { color: #f39c12; background: #fef5e7; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
</style>';
include '../layout/driver/header.php';
?>

<div class="driver-header" style="height: 120px; align-items: flex-start; padding-top: 30px;">
    <div style="width: 100%; display: flex; align-items: center; gap: 15px;">
        <a href="<?= $isCompleted ? 'driver_dashboard.php' : 'driver_trip_history.php' ?>"
            style="color: white; font-size: 1.2rem;"><i class="fas fa-arrow-left"></i></a>
        <h2 style="margin: 0; font-size: 1.4rem; font-weight: 600;">
            <?= $isCompleted ? 'Trip Completed! 🎉' : 'Trip Summary' ?>
        </h2>
    </div>
</div>

<div class="driver-container" style="margin-top: -50px; padding-bottom: 40px;">

    <div class="summary-header">
        <div class="icon-circle">
            <i class="fas <?= $tripData['display_icon'] ?>"></i>
        </div>
        <h3 style="margin: 0 0 5px 0; color: #333;"><?= htmlspecialchars($tripData['display_title']) ?></h3>
        <p style="margin: 0 0 15px 0; color: #888; font-size: 0.9rem;"><?= $tripData['display_type'] ?></p>

        <span class="<?= $statusClass ?>">
            <?= htmlspecialchars(ucfirst($tripData['status'] ?? 'Unknown')) ?>
        </span>

        <div class="stat-grid">
            <div class="stat-box"
                style="border-right: 1px solid #eee; border-bottom: 1px solid #eee; padding-bottom: 15px;">
                <div class="label">Date & Time</div>
                <div class="value">
                    <?= date('d M Y', strtotime($tripData['date'])) ?><br>
                    <span style="color: var(--primary-blue);"><?= $tripData['departure_time'] ?></span>
                </div>
            </div>
            <div class="stat-box" style="border-bottom: 1px solid #eee; padding-bottom: 15px;">
                <div class="label">Vehicle</div>
                <div class="value" style="padding-top: 10px;">
                    <?= htmlspecialchars($tripData['shuttle_id'] ?? 'N/A') ?>
                </div>
            </div>
            <div class="stat-box" style="border-right: 1px solid #eee; padding-top: 15px;">
                <div class="label">Average Rating</div>
                <div class="value" id="avgRatingUI"
                    style="color: #f39c12; font-size: 1.2rem; padding-top: 5px; transition: transform 0.2s ease;">
                    <i class="fas fa-star"
                        style="font-size: 1.1rem; margin-right: 4px;"></i><?= $ratingAvg > 0 ? $ratingAvg : '--' ?>
                </div>
            </div>
            <div class="stat-box" style="padding-top: 15px;">
                <div class="label">Total Fare</div>
                <div class="value" style="color: #2ecc71; font-size: 1.2rem; padding-top: 5px;">
                    RM <?= number_format($totalFare, 2) ?>
                </div>
            </div>
        </div>
    </div>

    <h4 style="margin: 0 0 15px 5px; color: #555; font-size: 1rem;">Passenger Manifest</h4>

    <div class="passenger-list">
        <div class="list-header">
            <span><?= count($passengerLog) ?> Boarded</span>
            <i class="fas fa-users" style="color: #aaa;"></i>
        </div>

        <?php if (empty($passengerLog)): ?>
            <div style="padding: 30px; text-align: center; color: #999;">
                <i class="fas fa-user-slash fa-2x" style="margin-bottom: 10px; color: #ddd;"></i>
                <p style="margin: 0;">No passengers recorded for this trip.</p>
            </div>
        <?php else: ?>
            <?php foreach ($passengerLog as $p):
                $initial = strtoupper(substr(trim($p['name']), 0, 1));
                ?>
                <div class="passenger-item">
                    <div class="avatar-placeholder"><?= $initial ?></div>
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: #333; margin-bottom: 4px;">
                            <?= htmlspecialchars($p['name']) ?>
                        </div>

                        <?php if ($tripType === 'ondemand'): ?>
                            <div style="font-size: 0.8rem; color: #666; margin-bottom: 2px;">
                                <i class="fas fa-arrow-up" style="color:var(--primary-blue); width:12px;"></i> From: <span
                                    style="font-weight:500;"><?= htmlspecialchars($p['pickup']) ?></span>
                            </div>
                            <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">
                                <i class="fas fa-arrow-down" style="color:#e74c3c; width:12px;"></i> To: <span
                                    style="font-weight:500;"><?= htmlspecialchars($p['dropoff']) ?></span>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 0.8rem; color: #666; margin-bottom: 4px;">
                                <i class="fas fa-map-pin" style="color:var(--primary-blue); width:12px;"></i> Boarded at: <span
                                    style="font-weight:500;"><?= htmlspecialchars($p['pickup']) ?></span>
                            </div>
                        <?php endif; ?>

                        <div style="font-size: 0.75rem; font-weight: 500; margin-top: 6px;">
                            <div style="color: #2ecc71; margin-bottom: 2px;">
                                <i class="far fa-clock" style="width:12px;"></i> Boarded:
                                <?= $p['check_in_time'] !== 'Unknown Time' ? date('h:i A', strtotime($p['check_in_time'])) : 'N/A' ?>
                            </div>
                            <div style="color: <?= $p['check_out_time'] !== 'Unknown Time' ? '#e74c3c' : '#aaa' ?>;">
                                <i class="far fa-clock" style="width:12px;"></i> Dropped:
                                <?= $p['check_out_time'] !== 'Unknown Time' ? date('h:i A', strtotime($p['check_out_time'])) : 'N/A' ?>
                            </div>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #2ecc71; font-weight: 600;">
                        <i class="fas fa-check"></i>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <h4 style="margin: 25px 0 15px 5px; color: #555; font-size: 1rem;">Passenger Reviews</h4>
    <div id="reviewsList" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
        <div
            style="padding: 20px; text-align: center; color: #a0aec0; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);">
            Loading reviews...
        </div>
    </div>

    <details class="trip-logs-container"
        style="margin-top: 20px; background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); overflow: hidden;">
        <summary
            style="padding: 15px 20px; font-weight: 600; color: #555; cursor: pointer; display: flex; justify-content: space-between; align-items: center; list-style: none;">
            <span>View Trip Logs</span>
            <i class="fas fa-chevron-down" style="color: #aaa;"></i>
        </summary>
        <div style="padding: 0 20px 20px;">
            <?php if (!empty($tripLogs)): ?>
                <?php foreach ($tripLogs as $log): ?>
                    <div style="border-bottom: 1px dashed #eee; padding: 10px 0; font-size: 0.85rem; display: flex; gap: 15px;">
                        <div style="font-weight: 700; color: #a0aec0; min-width: 45px;">
                            <?= $log['timestamp'] !== 'Unknown Time' ? date('H:i', strtotime($log['timestamp'])) : '--:--' ?>
                        </div>
                        <div style="color: #2d3748; flex: 1;"><?= htmlspecialchars($log['message']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #999; text-align: center; margin: 10px 0;">No logs available.</p>
            <?php endif; ?>
        </div>
    </details>

    <?php if ($isCompleted): ?>
        <a href="driver_dashboard.php" class="btn btn-primary"
            style="display: block; text-align: center; margin-top: 25px; border-radius: 16px; padding: 15px; font-weight:bold; width:100%; box-sizing:border-box;">BACK
            TO DASHBOARD</a>
    <?php else: ?>
        <a href="driver_trip_history.php" class="btn btn-secondary"
            style="display: block; text-align: center; margin-top: 25px; border-radius: 16px; padding: 15px; background: #e2e8f0; color: #4a5568; font-weight:bold; width:100%; box-sizing:border-box;">BACK
            TO HISTORY</a>
    <?php endif; ?>

</div>

<?php
// THE FIX 1: Real-time Javascript listener mapping array of booking IDs!
$extraScripts = "
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof firebase !== 'undefined') {
            const db = firebase.firestore();
            const bookingIds = " . json_encode($bookingIdsArray) . ";
            
            let ratingsMap = {};
            let reviewsMap = {};

            function renderRatings() {
                let total = 0;
                let count = 0;
                let reviewsHtml = '';
                
                for (let bid in ratingsMap) {
                    total += ratingsMap[bid].rating;
                    count++;
                    if (reviewsMap[bid]) {
                        reviewsHtml += reviewsMap[bid];
                    }
                }
                
                const avgElement = document.getElementById('avgRatingUI');
                if (avgElement) {
                    if (count > 0) {
                        const avg = (total / count).toFixed(1);
                        avgElement.innerHTML = '<i class=\"fas fa-star\" style=\"font-size: 1.1rem; margin-right: 4px;\"></i>' + avg;
                    } else {
                        avgElement.innerHTML = '<i class=\"fas fa-star\" style=\"font-size: 1.1rem; margin-right: 4px;\"></i>--';
                    }
                    avgElement.style.transform = 'scale(1.3)';
                    setTimeout(() => { avgElement.style.transform = 'scale(1)'; }, 200);
                }

                const reviewsContainer = document.getElementById('reviewsList');
                if (reviewsContainer) {
                    if (reviewsHtml === '') {
                        reviewsContainer.innerHTML = '<div style=\"padding: 20px; text-align: center; color: #a0aec0; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);\">No written reviews yet.</div>';
                    } else {
                        reviewsContainer.innerHTML = reviewsHtml;
                    }
                }
            }

            if (bookingIds.length > 0) {
                bookingIds.forEach(bid => {
                    db.collection('Ratings').where('booking_id', '==', bid).onSnapshot(snapshot => {
                        snapshot.forEach(doc => {
                            const data = doc.data();
                            if (data.rating) {
                                ratingsMap[bid] = { rating: parseFloat(data.rating) };
                                
                                const text = data.review || data.feedback || data.comment || '';
                                const tags = data.feedback_tags || [];
                                
                                let tagsHtml = '';
                                tags.forEach(tag => {
                                    tagsHtml += `<span style=\"background:#edf2f7; color:#4a5568; padding:3px 8px; border-radius:12px; font-size:0.75rem; margin-right:5px; display:inline-block; margin-top:5px;\">\${tag}</span>`;
                                });

                                if (text || tagsHtml) {
                                    const starRating = Math.round(data.rating || 0);
                                    const stars = '★'.repeat(starRating) + '☆'.repeat(5 - starRating);
                                    reviewsMap[bid] = `
                                    <div style=\"background: white; padding: 15px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 10px;\">
                                        <div style=\"color: #f39c12; font-size: 1rem; margin-bottom: 8px; letter-spacing: 2px;\">\${stars}</div>
                                        \${text ? `<div style=\"color: #4a5568; font-size: 0.95rem; line-height: 1.5; font-style: italic;\">\"\${text}\"</div>` : ''}
                                        \${tagsHtml ? `<div>\${tagsHtml}</div>` : ''}
                                    </div>`;
                                }
                            }
                        });
                        renderRatings();
                    });
                });
            } else {
                renderRatings(); // Instantly show 'No reviews' if nobody boarded
            }
        }
    });
</script>";

include '../layout/driver/footer.php';
?>