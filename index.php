<?php
require_once 'config.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// Detect login & role
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;

// ==========================================
// 1. THE GATEKEEPER: FETCH PUBLIC ANNOUNCEMENTS
// ==========================================
$now = date('Y-m-d H:i:s');
$nowTimestamp = strtotime($now);

$activeAnnouncements = [];

try {
    // Only pull active or scheduled documents
    $announcementsRef = $firestore->database()
        ->collection('Announcements')
        ->where('status', 'in', ['active', 'scheduled'])
        ->documents();

    foreach ($announcementsRef as $doc) {
        $data = $doc->data();

        // 1. Audience Check (Skip driver-only notices)
        $target = $data['target_audience'] ?? 'all';
        if ($target === 'driver') {
            continue;
        }

        // 2. Hard Block for Revoked (Just in case)
        if (isset($data['status']) && $data['status'] === 'revoked') {
            continue;
        }

        // 3. Expiry Check (Auto-hide old traffic reports)
        if (!empty($data['expires_at']) && strtotime($data['expires_at']) <= $nowTimestamp) {
            continue;
        }

        // 4. Schedule Check (Time-Travel Fix)
        $isVisible = false;
        if ($data['status'] === 'active') {
            $isVisible = true;
        } elseif ($data['status'] === 'scheduled' && !empty($data['schedule_time'])) {
            if (strtotime($data['schedule_time']) <= $nowTimestamp) {
                $isVisible = true;
            }
        }

        if ($isVisible) {
            // Assign a sorting time (use schedule time if available, otherwise creation time)
            $data['sort_time'] = !empty($data['schedule_time']) ? strtotime($data['schedule_time']) : strtotime($data['created_at']);
            $activeAnnouncements[] = $data;
        }
    }
} catch (Exception $e) {
    // Fail silently on the public page
}

// Priority Weight-Based Sort: Emergency(3) > Warning/Ops(2) > General(1), then newest first
usort($activeAnnouncements, function ($a, $b) {
    $getWeight = function ($ann) {
        $tag = $ann['tag'] ?? '';
        if (strpos($tag, 'Emergency') !== false || strpos($tag, 'Urgent') !== false) return 3;
        if (strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false || strpos($tag, 'Weather') !== false) return 2;
        return 1;
    };
    $wa = $getWeight($a);
    $wb = $getWeight($b);
    if ($wb !== $wa) return $wb - $wa; // Higher weight first
    return $b['sort_time'] - $a['sort_time']; // Newest first within same weight
});

// Limit to 4 for a clean discovery feed (no wall of text)
$activeAnnouncements = array_slice($activeAnnouncements, 0, 4);

// ==========================================
// 2. LIVE FLEET COUNTER
// ==========================================
$liveShuttleCount = 0;
try {
    $shuttlesRef = $firestore->database()->collection('Shuttles')
        ->where('status', '=', 'active')
        ->where('is_online', '=', true)
        ->documents();
    foreach ($shuttlesRef as $s) {
        if ($s->exists())
            $liveShuttleCount++;
    }
} catch (Exception $e) {
    // Fail silently
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusPulse – Smart Shuttle Service</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Modern Landing Page Styles */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #003366 0%, #004080 100%);
            color: white;
            padding: 80px 20px 100px 20px;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -50px;
            left: 0;
            width: 100%;
            height: 100px;
            background: white;
            transform: skewY(-3deg);
        }

        .hero-content {
            max-width: 1100px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }

        .hero-left {
            text-align: left;
        }

        .hero-right {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .hero-mockup {
            max-height: 380px;
            width: auto;
            filter: drop-shadow(0 20px 40px rgba(0, 0, 0, 0.35));
            animation: floatMockup 4s ease-in-out infinite;
        }

        @keyframes floatMockup {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-12px);
            }
        }

        .hero h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .hero p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 35px;
            max-width: 550px;
        }

        /* Live Fleet Banner */
        .live-fleet-banner {
            background: #f0fdf4;
            border-top: 3px solid #22c55e;
            border-bottom: 1px solid #dcfce7;
            padding: 14px 20px;
            text-align: center;
            font-size: 0.95rem;
            font-weight: 600;
            color: #166534;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            z-index: 3;
        }

        .live-fleet-banner .pulse-dot {
            width: 10px;
            height: 10px;
            background: #22c55e;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 1.5s ease infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5);
            }

            50% {
                box-shadow: 0 0 0 7px rgba(34, 197, 94, 0);
            }
        }

        /* Role-based dashboard buttons */
        .btn-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .btn-dashboard:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }

        .btn-dashboard-admin {
            background: #f59e0b;
            color: #1c1917;
        }

        .btn-dashboard-driver {
            background: #3b82f6;
            color: white;
        }

        .hybrid-badge {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        /* App Badges */
        .app-badges {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .store-btn {
            background: white;
            color: #333;
            padding: 12px 25px;
            border-radius: 12px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .store-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .store-text {
            text-align: left;
            line-height: 1.2;
        }

        .store-text span {
            display: block;
            font-size: 0.75rem;
            color: #666;
        }

        .store-text strong {
            font-size: 1.1rem;
            color: #000;
        }

        /* Smart Recommendation Section */
        .smart-rec-section {
            padding: 60px 20px;
            background: white;
            text-align: center;
        }

        .rec-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            max-width: 1100px;
            margin: 40px auto 0 auto;
        }

        .rec-card {
            background: #fff;
            border: 1px solid #eee;
            border-radius: 20px;
            padding: 30px;
            text-align: left;
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }

        .rec-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border-color: var(--primary-blue);
        }

        .rec-icon {
            width: 60px;
            height: 60px;
            background: #e3f2fd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            color: var(--primary-blue);
            font-size: 1.5rem;
        }

        .rec-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #fff3cd;
            color: #856404;
            padding: 5px 10px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Hybrid Features */
        .features-section {
            padding: 80px 20px;
            background: #f8f9fa;
        }

        .feature-box {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.03);
            text-align: center;
            height: 100%;
        }

        /* Announcements & Live Feed */
        .announcement-section {
            padding: 80px 20px;
            background: white;
        }

        .announcement-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .announcement-card {
            background: white;
            border: 1px solid #eee;
            border-radius: 12px;
            padding: 25px;
            border-left: 5px solid var(--primary-blue);
            /* Default color */
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
        }

        .announcement-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            transform: translateY(-2px);
        }

        /* Dynamic Tags */
        .feed-tag {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 12px;
        }

        .tag-emergency {
            background: #ffebee;
            color: #c62828;
        }

        .tag-warning {
            background: #fff8e1;
            color: #f57f17;
        }

        .tag-info {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Dynamic Card Borders */
        .card-emergency {
            border-left-color: #c62828;
        }

        .card-warning {
            border-left-color: #f57f17;
        }

        .card-info {
            border-left-color: #1565c0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hero-left {
                text-align: center;
            }

            .hero-right {
                display: none;
            }

            .hero h1 {
                font-size: 2.2rem;
            }

            .store-btn {
                width: 100%;
                justify-content: center;
            }

            .app-badges {
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <?php include 'layout/public_header.php'; ?>

    <section class="hero" id="download-section">
        <div class="hero-content">

            <!-- LEFT COLUMN: Text & Buttons -->
            <div class="hero-left">
                <div class="hybrid-badge" style="margin-bottom:20px;">
                    <i class="fas fa-sync-alt"></i> Hybrid Mobility System
                </div>
                <h1>Your Campus Commute,<br>Reimagined.</h1>
                <p>Experience the flexibility of a <strong>Hybrid Shuttle Network</strong>. Whether you prefer a fixed
                    schedule or need an on-demand ride, CampusPulse adapts to your student lifestyle.</p>

                <div class="app-badges" style="justify-content: flex-start;">
                    <?php if ($isLoggedIn && $userRole === 'admin'): ?>
                        <a href="admin/admin_dashboard.php" class="btn-dashboard btn-dashboard-admin">
                            <i class="fas fa-tachometer-alt"></i> Go to Admin Dashboard
                        </a>
                    <?php elseif ($isLoggedIn && $userRole === 'driver'): ?>
                        <a href="driver/driver_dashboard.php" class="btn-dashboard btn-dashboard-driver">
                            <i class="fas fa-satellite-dish"></i> Open Driver Terminal
                        </a>
                    <?php else: ?>
                        <a href="#" class="store-btn">
                            <i class="fab fa-apple fa-2x"></i>
                            <div class="store-text">
                                <span>Download on the</span>
                                <strong>App Store</strong>
                            </div>
                        </a>
                        <a href="#" class="store-btn">
                            <i class="fab fa-google-play fa-2x" style="color: #3ddc84;"></i>
                            <div class="store-text">
                                <span>GET IT ON</span>
                                <strong>Google Play</strong>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 20px;">
                    <a href="shuttle_schedule.php"
                        style="color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.9rem; border-bottom: 1px dashed rgba(255,255,255,0.5);">
                        View web schedule <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- RIGHT COLUMN: App Mockup -->
            <div class="hero-right">
                <img src="img/app-mockup.jpg" alt="CampusPulse App Preview" class="hero-mockup"
                    onerror="this.style.display='none'">
            </div>

        </div>
    </section>

    <!-- Live Fleet Counter Banner -->
    <div class="live-fleet-banner">
        <span class="pulse-dot"></span>
        <?php if ($liveShuttleCount > 0): ?>
            Live Now: <strong><?= $liveShuttleCount ?> Shuttle<?= $liveShuttleCount !== 1 ? 's' : '' ?></strong> Currently
            on the Road
        <?php else: ?>
            No shuttles are currently active. Check back during operating hours.
        <?php endif; ?>
    </div>

    <section class="smart-rec-section">
        <div style="max-width: 800px; margin: 0 auto;">
            <h2 style="color:var(--primary-blue); font-size: 2.2rem; margin-bottom: 10px;">Smart Travel Assistant</h2>
            <p style="color:#666;">The CampusPulse app uses modern features for student comfort.</p>
        </div>

        <div class="rec-grid">
            <div class="rec-card">
                <div class="rec-badge">✨ Smart</div>
                <div class="rec-icon"><i class="fas fa-route"></i></div>
                <h3 style="margin-bottom: 10px; color:#333;">Smart Recommendation</h3>
                <p style="color:#666; line-height: 1.6;">Based on your class timetable, the app suggests the fastest
                    shuttle route before you even ask.</p>
            </div>

            <div class="rec-card">
                <div class="rec-badge">⚡ Real-Time</div>
                <div class="rec-icon"><i class="fas fa-hourglass-half"></i></div>
                <h3 style="margin-bottom: 10px; color:#333;">Wait-Time Prediction</h3>
                <p style="color:#666; line-height: 1.6;">Know exactly when the next bus arrives with predictive ETAs
                    based on live traffic data.</p>
            </div>

            <div class="rec-card">
                <div class="rec-badge">💎 Premium</div>
                <div class="rec-icon"><i class="fas fa-star"></i></div>
                <h3 style="margin-bottom: 10px; color:#333;">Personalized Alerts</h3>
                <p style="color:#666; line-height: 1.6;">Get notified only for the routes you use. Rain delays? Traffic
                    jams? You'll know first.</p>
            </div>
        </div>
    </section>

    <section class="features-section">
        <div style="max-width: 1100px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 50px;">
                <h2 style="color:var(--primary-blue); font-size: 2rem;">Why Go Hybrid?</h2>
                <p style="color:#666;">Two modes, one seamless experience.</p>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 40px;">
                <div class="feature-box">
                    <div
                        style="background:#e3f2fd; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto;">
                        <i class="far fa-calendar-check fa-2x" style="color:var(--primary-blue);"></i>
                    </div>
                    <h3>1. Scheduled Service</h3>
                    <p style="color:#666;">Perfect for daily classes. Reliable, fixed timings ensure you never miss a
                        lecture during peak hours.</p>
                </div>

                <div class="feature-box">
                    <div
                        style="background:#e3f2fd; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto;">
                        <i class="fas fa-hand-pointer fa-2x" style="color:var(--primary-blue);"></i>
                    </div>
                    <h3>2. On-Demand Rides</h3>
                    <p style="color:#666;">Need a ride during off-peak hours? Book a shuttle instantly through the app,
                        just like e-hailing.</p>
                </div>

                <div class="feature-box">
                    <div
                        style="background:#e3f2fd; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto;">
                        <i class="fas fa-mobile-alt fa-2x" style="color:var(--primary-blue);"></i>
                    </div>
                    <h3>3. Live Tracking</h3>
                    <p style="color:#666;">Watch your shuttle move on the map in real-time. No more guessing games at
                        the bus stop.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="announcements" class="announcement-section">
        <div style="max-width: 1100px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px;">
                <div>
                    <h2 style="color:var(--primary-blue); margin:0; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-broadcast-tower" style="color: #e74c3c;"></i> Live System Feed
                    </h2>
                    <p style="color: #666; margin: 5px 0 0 0; font-size: 0.9rem;">Real-time service updates and driver
                        reports.</p>
                </div>
                <a href="public_announcements.php"
                    style="color:var(--primary-blue); font-weight:600; text-decoration:none;">View All <i
                        class="fas fa-arrow-right"></i></a>
            </div>

            <?php if (empty($activeAnnouncements)): ?>
                <div
                    style="text-align:center; padding:60px; background:#f8f9fa; border-radius:16px; border: 2px dashed #eee;">
                    <i class="fas fa-check-circle" style="font-size: 3rem; color: #27ae60; margin-bottom: 20px;"></i>
                    <h3 style="color: #333; margin: 0 0 5px 0;">All Systems Normal</h3>
                    <p style="color:#777; margin: 0;">There are no service delays or active alerts at the moment.</p>
                </div>
            <?php else: ?>
                <div class="announcement-grid">
                    <?php foreach ($activeAnnouncements as $announcement):
                        // Dynamic styling based on the tag
                        $tag = $announcement['tag'] ?? '#Info';
                        $isEmergency  = strpos($tag, 'Emergency') !== false || strpos($tag, 'Urgent') !== false;
                        $isWarning    = strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false || strpos($tag, 'Weather') !== false;

                        // Weight for this card
                        $weight = $isEmergency ? 3 : ($isWarning ? 2 : 1);

                        $tagClass  = $isEmergency ? 'tag-emergency' : ($isWarning ? 'tag-warning' : 'tag-info');
                        $cardClass = $isEmergency ? 'card-emergency' : ($isWarning ? 'card-warning' : 'card-info');

                        // FontAwesome icon per weight
                        if ($isEmergency) {
                            $icon = '<i class="fas fa-exclamation-circle"></i>';
                        } elseif ($isWarning) {
                            $icon = '<i class="fas fa-bolt"></i>';
                        } else {
                            $icon = '<i class="fas fa-info-circle"></i>';
                        }

                        // Operational/Warning cards get a quieter message font size
                        $msgFontSize = ($weight === 2) ? '0.85rem' : '0.95rem';
                        ?>
                        <div class="announcement-card <?= $cardClass ?>">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <span class="feed-tag <?= $tagClass ?>"><?= $icon ?> <?= htmlspecialchars($tag) ?></span>
                                <small style="color:#999; font-size:0.8rem; font-weight: 500;">
                                    <i class="far fa-clock"></i> <?= date('d M, h:i A', $announcement['sort_time']) ?>
                                </small>
                            </div>

                            <h3 style="margin: 0 0 8px 0; font-size:1.1rem; color:#333; font-weight:700;">
                                <?= htmlspecialchars($announcement['title']) ?>
                            </h3>

                            <p style="color:#555; line-height:1.6; margin:0; font-size:<?= $msgFontSize ?>; flex-grow: 1;">
                                <?= nl2br(htmlspecialchars($announcement['message'])) ?>
                            </p>

                            <?php if (!empty($announcement['location_name'])): ?>
                                <div
                                    style="margin-top: 15px; padding-top: 12px; border-top: 1px dashed #eee; font-size: 0.8rem; color: #e74c3c; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($announcement['location_name']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section id="join-fleet" style="padding: 80px 20px; background: #003366; color: white; text-align: center;">
        <div style="max-width: 800px; margin: 0 auto;">
            <h2 style="font-size: 2.2rem; margin-bottom: 15px; color: white;">Drive with CampusPulse</h2>
            <p style="font-size: 1.1rem; opacity: 0.9; margin-bottom: 30px;">
                Help fellow students commute safely while earning. Join our fleet of official university shuttle drivers
                today.
            </p>
            <a href="driver_application.php" class="store-btn"
                style="display: inline-flex; width: auto; font-weight: 600; padding: 15px 30px; font-size: 1.1rem; justify-content: center;">
                <i class="fas fa-car-side"></i> Apply to Become a Driver
            </a>
        </div>
    </section>

    <?php include 'layout/footer.php'; ?>

</body>

</html>