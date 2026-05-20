<?php
require_once 'config.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;

// ==========================================
// 1. THE GATEKEEPER: FETCH PUBLIC ANNOUNCEMENTS
// ==========================================
$now = date('Y-m-d H:i:s');
$nowTimestamp = strtotime($now);
$activeAnnouncements = [];

try {
    $announcementsRef = $firestore->database()
        ->collection('Announcements')
        ->where('status', 'in', ['active', 'scheduled'])
        ->documents();

    foreach ($announcementsRef as $doc) {
        $data = $doc->data();
        $target = $data['target_audience'] ?? 'all';
        $tag = $data['tag'] ?? '';

        if (strtolower($target) !== 'public' && stripos($tag, 'Info') === false)
            continue;
        if (isset($data['status']) && $data['status'] === 'revoked')
            continue;
        if (!empty($data['expires_at']) && strtotime($data['expires_at']) <= $nowTimestamp)
            continue;

        $isVisible = false;
        if ($data['status'] === 'active') {
            $isVisible = true;
        } elseif ($data['status'] === 'scheduled' && !empty($data['schedule_time'])) {
            if (strtotime($data['schedule_time']) <= $nowTimestamp) {
                $isVisible = true;
            }
        }

        if ($isVisible) {
            $data['sort_time'] = !empty($data['schedule_time']) ? strtotime($data['schedule_time']) : strtotime($data['created_at']);
            $activeAnnouncements[] = $data;
        }
    }
} catch (Exception $e) {
}

usort($activeAnnouncements, function ($a, $b) {
    $getWeight = function ($ann) {
        $tag = $ann['tag'] ?? '';
        if (strpos($tag, 'Emergency') !== false || strpos($tag, 'Urgent') !== false)
            return 3;
        if (strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false || strpos($tag, 'Weather') !== false)
            return 2;
        return 1;
    };
    $wa = $getWeight($a);
    $wb = $getWeight($b);
    if ($wb !== $wa)
        return $wb - $wa;
    return $b['sort_time'] - $a['sort_time'];
});

$activeAnnouncements = array_slice($activeAnnouncements, 0, 8);

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
}

// ==========================================
// HEADER CONFIGURATION
// ==========================================
$depth = '';
$pageTitle = 'CampusPulse – Smart Shuttle Service';

// Capture index-specific CSS and GSAP scripts
ob_start();
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
<style>
    :root {
        --primary-dark: #0A3060;
        --bg-light: #F8F9FA;
        --text-main: #1A1A24;
        --text-muted: #64748B;
        --glass-bg: rgba(255, 255, 255, 0.85);
        --glass-border: rgba(255, 255, 255, 0.4);
    }

    body {
        background-color: var(--bg-light);
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
    }

    .layout-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 24px;
    }

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
        background: var(--bg-light);
        transform: skewY(-3deg);
    }

    .hero-glow {
        position: absolute;
        width: 600px;
        height: 600px;
        background: var(--accent-yellow);
        filter: blur(150px);
        opacity: 0.15;
        border-radius: 50%;
        top: -100px;
        right: -100px;
        animation: pulse-glow 6s infinite alternate;
        z-index: 1;
    }

    @keyframes pulse-glow {
        0% {
            transform: scale(1);
            opacity: 0.15;
        }

        100% {
            transform: scale(1.2);
            opacity: 0.25;
        }
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

    .hero h1 {
        font-size: clamp(2.5rem, 5vw, 3.5rem);
        font-weight: 700;
        margin-bottom: 20px;
        line-height: 1.2;
        font-family: 'Montserrat', sans-serif;
    }

    .hero p {
        font-size: 1.1rem;
        opacity: 0.9;
        margin-bottom: 35px;
        max-width: 550px;
    }

    .phone-mockup {
        width: 320px;
        height: 650px;
        background: #ffffff;
        border-radius: 48px;
        border: 12px solid #1A1A24;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4), inset 0 0 0 4px #333;
        position: relative;
        overflow: hidden;
        margin: 0 auto;
        transform: rotate(-5deg) translateY(20px);
        transition: transform 0.5s ease;
    }

    .phone-mockup:hover {
        transform: rotate(0deg) translateY(0px);
    }

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
        color: white;
    }

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

    .smart-rec-section {
        padding: 60px 20px;
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

    .features-section {
        padding: 80px 20px;
    }

    .feature-box {
        background: white;
        padding: 30px;
        border-radius: 16px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.03);
        text-align: center;
        height: 100%;
    }

    .timeline-section {
        padding: 100px 0 20px 0;
        background-color: var(--bg-light);
    }

    .timeline-track {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        width: 4px;
        height: 100%;
        background: #E2E8F0;
        top: 0;
        z-index: 1;
    }

    .timeline-progress {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 0%;
        background: var(--accent-yellow);
        box-shadow: 0 0 15px var(--accent-yellow);
    }

    .timeline-step {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 120px;
        position: relative;
        z-index: 2;
    }

    .timeline-step:nth-child(even) {
        flex-direction: row-reverse;
    }

    .timeline-step:last-child {
        margin-bottom: 0;
    }

    .timeline-step.full-width-step {
        flex-direction: column !important;
        align-items: center;
        padding-top: 60px;
    }

    .timeline-content {
        width: 45%;
    }

    .timeline-content h2 {
        font-size: 2.5rem;
        margin-bottom: 16px;
        font-family: 'Montserrat', sans-serif;
        color: var(--primary-blue);
    }

    .timeline-content p {
        color: var(--text-muted);
        line-height: 1.6;
    }

    .timeline-node {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 24px;
        height: 24px;
        background: var(--bg-light);
        border: 4px solid var(--primary-blue);
        border-radius: 50%;
        z-index: 10;
        transition: all 0.3s ease;
    }

    .timeline-node.active {
        background: var(--accent-yellow);
        border-color: var(--accent-yellow);
        box-shadow: 0 0 20px rgba(240, 171, 0, 0.6);
        transform: translate(-50%, -50%) scale(1.3);
    }

    .timeline-visual {
        width: 45%;
        display: flex;
        justify-content: center;
    }

    .glass-card {
        background: var(--glass-bg);
        backdrop-filter: blur(20px);
        border-radius: 28px;
        padding: 40px;
        box-shadow: 0 20px 40px rgba(16, 76, 151, 0.08);
        border: 1px solid var(--glass-border);
        width: 100%;
        max-width: 450px;
    }

    .step-tag {
        display: inline-block;
        padding: 6px 16px;
        background: rgba(16, 76, 151, 0.1);
        color: var(--primary-blue);
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 16px;
    }

    .zone-pulse {
        position: relative;
        width: 80px;
        height: 80px;
        background: var(--primary-blue);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 24px;
        color: white;
        font-size: 40px;
        box-shadow: 0 10px 20px rgba(16, 76, 151, 0.3);
    }

    .zone-pulse::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: 50%;
        background: rgba(16, 76, 151, 0.5);
        z-index: -1;
        animation: radar-pulse 2s infinite ease-out;
    }

    @keyframes radar-pulse {
        0% {
            transform: scale(1);
            opacity: 1;
        }

        100% {
            transform: scale(2.2);
            opacity: 0;
        }
    }

    .booking-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 32px;
        perspective: 1000px;
        width: 100%;
    }

    .mode-card {
        background: white;
        border-radius: 28px;
        padding: 40px 32px;
        border: 1px solid #E2E8F0;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        text-align: left;
        position: relative;
        overflow: hidden;
        z-index: 5;
    }

    .mode-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 30px 60px rgba(16, 76, 151, 0.1);
        border-color: var(--primary-blue);
    }

    .mode-icon {
        width: 72px;
        height: 72px;
        background: var(--bg-light);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
        color: var(--primary-blue);
        margin-bottom: 24px;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
    }

    .mode-card h3 {
        font-size: 1.5rem;
        margin-bottom: 12px;
        font-family: 'Montserrat', sans-serif;
        color: var(--primary-blue);
    }

    .live-map-ui {
        background: #E2E8F0;
        border-radius: 24px;
        height: 250px;
        width: 100%;
        position: relative;
        overflow: hidden;
        background-image: radial-gradient(rgba(16, 76, 151, 0.2) 2px, transparent 2px);
        background-size: 30px 30px;
        border: 2px solid white;
    }

    .map-route {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 80%;
        height: 4px;
        background: var(--primary-blue);
        border-radius: 2px;
    }

    .bus-marker {
        position: absolute;
        top: 50%;
        left: 20%;
        transform: translate(-50%, -50%);
        width: 48px;
        height: 48px;
        background: var(--primary-blue);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        border: 4px solid white;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        z-index: 10;
    }

    .pickup-marker {
        position: absolute;
        top: 50%;
        left: 80%;
        transform: translate(-50%, -50%);
        width: 24px;
        height: 24px;
        background: #22C55E;
        border-radius: 50%;
        border: 4px solid white;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .eta-bubble {
        position: absolute;
        top: -50px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--accent-yellow);
        color: white;
        padding: 6px 12px;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.85rem;
        white-space: nowrap;
    }

    .eta-bubble::after {
        content: '';
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(-50%);
        border-width: 6px 6px 0;
        border-style: solid;
        border-color: var(--accent-yellow) transparent transparent transparent;
    }

    .review-cta {
        background: var(--bg-light);
        text-align: center;
        padding: 40px 24px 80px 24px;
        position: relative;
        z-index: 10;
    }

    .stars {
        display: flex;
        justify-content: center;
        gap: 16px;
        margin: 32px 0;
        color: var(--accent-yellow);
        font-size: 40px;
    }

    .stars i {
        filter: drop-shadow(0 4px 10px rgba(240, 171, 0, 0.4));
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .stars i:hover {
        transform: scale(1.15);
    }

    .stars i.inactive {
        color: #cbd5e1;
        filter: none;
    }

    .end-trip-btn {
        display: inline-block;
        margin-top: 24px;
        padding: 16px 48px;
        background: var(--accent-yellow);
        color: white;
        text-decoration: none;
        border-radius: 20px;
        font-weight: 800;
        font-size: 1.1rem;
        box-shadow: 0 10px 20px rgba(240, 171, 0, 0.3);
        transition: all 0.3s ease;
        border: none;
        cursor: pointer;
    }

    .end-trip-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 25px rgba(240, 171, 0, 0.4);
    }

    .announcement-section {
        padding: 80px 20px;
        background: white;
        overflow: hidden;
    }

    .carousel-wrapper {
        width: 100%;
        overflow: hidden;
        position: relative;
        padding: 20px 0;
        -webkit-mask-image: linear-gradient(to right, transparent, black 5%, black 95%, transparent);
        mask-image: linear-gradient(to right, transparent, black 5%, black 95%, transparent);
    }

    .carousel-track {
        display: flex;
        gap: 25px;
        width: max-content;
        animation: scrollCarousel 40s linear infinite;
    }

    .carousel-track:hover {
        animation-play-state: paused;
    }

    @keyframes scrollCarousel {
        0% {
            transform: translateX(0);
        }

        100% {
            transform: translateX(calc(-50% - 12.5px));
        }
    }

    .announcement-card {
        background: white;
        border: 1px solid #eee;
        border-radius: 12px;
        padding: 25px;
        border-left: 5px solid var(--primary-blue);
        transition: all 0.2s;
        display: flex;
        flex-direction: column;
        width: 350px;
        flex-shrink: 0;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    }

    .announcement-card:hover {
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
    }

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

    .card-emergency {
        border-left-color: #c62828;
    }

    .card-warning {
        border-left-color: #f57f17;
    }

    .card-info {
        border-left-color: #1565c0;
    }

    .reveal,
    .fade-up {
        opacity: 0;
        transform: translateY(40px);
        transition: all 0.8s ease-out;
    }

    .reveal.active,
    .fade-up.active {
        opacity: 1;
        transform: translateY(0);
    }

    @media (max-width: 968px) {
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

        .timeline-track,
        .timeline-node {
            display: none;
        }

        .timeline-step,
        .timeline-step:nth-child(even),
        .timeline-step.full-width-step {
            flex-direction: column;
            text-align: center;
            gap: 40px;
            margin-bottom: 80px;
            padding-top: 0 !important;
        }

        .timeline-content,
        .timeline-visual {
            width: 100%;
        }

        .booking-grid {
            grid-template-columns: 1fr;
        }

        .announcement-card {
            width: 280px;
        }
    }
</style>
<?php
$extraHead = ob_get_clean();
include $depth . 'layout/public/header.php';
?>

<section class="hero" id="download-section">
    <div class="hero-glow"></div>
    <div class="hero-content">
        <div class="hero-left reveal">
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

        <div class="hero-right reveal">
            <div class="phone-mockup">
                <img src="img/campuspulse_app_mockup.png" alt="CampusPulse App Interface"
                    style="width: 100%; height: 100%; object-fit: cover; border-radius: 36px;">
            </div>
        </div>
    </div>
</section>

<div class="live-fleet-banner">
    <span class="pulse-dot"></span>
    <?php if ($liveShuttleCount > 0): ?>
        Live Now: <strong id="shuttleCounter" data-target="<?= $liveShuttleCount ?>">0</strong>
        &nbsp;Shuttle<?= $liveShuttleCount !== 1 ? 's' : '' ?> Currently
        on the Road
    <?php else: ?>
        No shuttles are currently active. Check back during operating hours.
    <?php endif; ?>
</div>

<section class="smart-rec-section">
    <div style="max-width: 800px; margin: 0 auto;" class="reveal">
        <h2 style="color:var(--primary-blue); font-size: 2.2rem; margin-bottom: 10px; font-family: 'Montserrat';">
            Smart Travel Assistant</h2>
        <p style="color:#666;">The CampusPulse app uses modern features for student comfort.</p>
    </div>

    <div class="rec-grid">
        <div class="rec-card reveal">
            <div class="rec-badge">✨ Smart</div>
            <div class="rec-icon"><i class="fas fa-route"></i></div>
            <h3 style="margin-bottom: 10px; color:#333; font-family:'Montserrat';">Smart Recommendation</h3>
            <p style="color:#666; line-height: 1.6;">Based on your class timetable, the app suggests the fastest
                shuttle route before you even ask.</p>
        </div>

        <div class="rec-card reveal" style="transition-delay: 0.1s;">
            <div class="rec-badge">⚡ Real-Time</div>
            <div class="rec-icon"><i class="fas fa-hourglass-half"></i></div>
            <h3 style="margin-bottom: 10px; color:#333; font-family:'Montserrat';">Wait-Time Prediction</h3>
            <p style="color:#666; line-height: 1.6;">Know exactly when the next shuttle arrives with predictive ETAs
                based on live traffic data.</p>
        </div>

        <div class="rec-card reveal" style="transition-delay: 0.2s;">
            <div class="rec-badge">💎 Premium</div>
            <div class="rec-icon"><i class="fas fa-star"></i></div>
            <h3 style="margin-bottom: 10px; color:#333; font-family:'Montserrat';">Personalized Alerts</h3>
            <p style="color:#666; line-height: 1.6;">Get notified only for the routes you use. Rain delays? Traffic
                jams? You'll know first.</p>
        </div>
    </div>
</section>

<section id="onboard" class="timeline-section layout-container">

    <div style="text-align: center; margin-bottom: 80px;" class="reveal">
        <h2 style="color:var(--primary-blue); font-size: 2.5rem; font-family: 'Montserrat';">How CampusPulse Works
        </h2>
        <p style="color:#666; font-size: 1.1rem; max-width: 600px; margin: 0 auto;">Your complete guide to mastering
            the campus commute from booking to boarding.</p>
    </div>

    <div style="position: relative;">

        <div class="timeline-track">
            <div class="timeline-progress"></div>
        </div>

        <div class="timeline-step">
            <div class="timeline-node"></div>
            <div class="timeline-content fade-up">
                <div class="step-tag">Step 01</div>
                <h2>Connect & Detect</h2>
                <p>Sign in securely using your official <b>UniKL Student Email</b>. Enable location services, and
                    the
                    app instantly detects your current campus zone (e.g., UniKL MIIT) to filter the right schedules
                    for
                    you.</p>
            </div>
            <div class="timeline-visual fade-up">
                <div class="glass-card" style="text-align: center;">
                    <div class="zone-pulse"><i class="ph-fill ph-radar"></i></div>
                    <h3 style="font-size: 1.25rem; font-family:'Montserrat';">Zone Auto-Detection</h3>
                    <p style="font-size: 0.9rem; margin-top: 8px;">Seamlessly locks you into your active campus.</p>
                </div>
            </div>
        </div>

        <div class="timeline-step">
            <div class="timeline-node"></div>
            <div class="timeline-content fade-up">
                <div class="step-tag">Step 02</div>
                <h2>Power the Wallet</h2>
                <p>Top up your <b>Campus Credits</b> via a secure online gateway. Enjoy a standard, flat-rate fare
                    of
                    exactly <b>RM 2.00</b> for every ride. 100% cashless, 100% hassle-free.</p>
            </div>
            <div class="timeline-visual fade-up">
                <div class="glass-card"
                    style="background: linear-gradient(135deg, var(--primary-blue), var(--primary-dark)); color: white; border: none;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px;">
                        <i class="ph-fill ph-wallet" style="font-size: 32px; color: var(--accent-yellow);"></i>
                        <i class="ph-bold ph-contactless-payment" style="font-size: 32px;"></i>
                    </div>
                    <p style="color: rgba(255,255,255,0.7); font-size: 0.9rem; margin-bottom: 4px;">Available
                        Balance</p>
                    <h2 style="color: white; font-size: 2.5rem; font-family:'Montserrat';">RM 20.00</h2>
                </div>
            </div>
        </div>

        <div class="timeline-step full-width-step">
            <div class="timeline-node" style="top: 0;"></div>

            <div class="timeline-content fade-up"
                style="width: 100%; margin-bottom: 40px; text-align: center; position: relative; z-index: 5;">
                <div class="step-tag">Step 03</div>
                <h2>Pick Your Path</h2>
                <p style="margin: 0 auto; max-width: 600px;">Three distinct ways to ride, designed around a
                    student's
                    chaotic schedule.</p>
            </div>
            <div class="timeline-visual fade-up" style="width: 100%;">
                <div class="booking-grid">
                    <div class="mode-card">
                        <div class="mode-icon"><i class="ph-duotone ph-sparkle"></i></div>
                        <h3>Smart Planner</h3>
                        <p>Sync your timetable once. Our algorithm analyzes your class times and live traffic to
                            tell
                            you exactly when to leave your room.</p>
                    </div>
                    <div class="mode-card">
                        <div class="mode-icon"><i class="ph-duotone ph-calendar-check"></i></div>
                        <h3>Scheduled Service</h3>
                        <p>Don't leave it to chance. Browse fixed schedules during busy morning and evening periods
                            and
                            reserve your seat in advance.</p>
                    </div>
                    <div class="mode-card">
                        <div class="mode-icon"><i class="ph-duotone ph-lightning"></i></div>
                        <h3>On-Demand Ride</h3>
                        <p>Need to move immediately during off-peak hours? Send an instant request to available
                            campus
                            drivers with a single tap.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="timeline-step">
            <div class="timeline-node"></div>
            <div class="timeline-content fade-up">
                <div class="step-tag">Step 04</div>
                <h2>The Live Transit Experience</h2>
                <p>Once booked, switch to the Tracking tab. Watch your designated shuttle move on a live radar. Our
                    system calculates dynamic <b>Live ETAs</b> based on real-time traffic conditions.</p>
                <ul style="list-style: none; margin-top: 16px;">
                    <li
                        style="margin-bottom: 12px; display: flex; align-items: center; gap: 12px; color: var(--text-muted); font-weight: 500;">
                        <i class="ph-fill ph-check-circle" style="color: #22C55E; font-size: 20px;"></i> Real-Time
                        GPS Tracking
                    </li>
                    <li
                        style="margin-bottom: 12px; display: flex; align-items: center; gap: 12px; color: var(--text-muted); font-weight: 500;">
                        <i class="ph-fill ph-check-circle" style="color: #22C55E; font-size: 20px;"></i> Smart
                        Traffic Delay Warnings
                    </li>
                    <li
                        style="display: flex; align-items: center; gap: 12px; color: var(--text-muted); font-weight: 500;">
                        <i class="ph-fill ph-check-circle" style="color: #22C55E; font-size: 20px;"></i> Digital QR
                        Boarding Pass
                    </li>
                </ul>
            </div>
            <div class="timeline-visual fade-up">
                <div class="glass-card" style="padding: 24px;">
                    <div class="live-map-ui">
                        <div class="map-route"></div>
                        <div class="pickup-marker"></div>
                        <div class="bus-marker gs-bus">
                            <div class="eta-bubble">3 Mins Away</div>
                            <i class="ph-fill ph-bus"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="review-cta">
    <div class="layout-container reveal">
        <div class="step-tag" style="background: rgba(16, 76, 151, 0.1); color: var(--primary-blue);">Final Step
        </div>
        <h2 style="color: var(--primary-blue); font-size: 3rem; margin: 16px 0; font-family:'Montserrat';">Ride &
            Review</h2>
        <p style="color: var(--text-muted); font-size: 1.1rem; max-width: 600px; margin: 0 auto;">Arrived
            safely? Rate your driver below. Your feedback helps maintain the high standards of UniKL mobility.</p>

        <div class="stars" id="interactive-stars">
            <i class="ph-fill ph-star" data-value="1"></i>
            <i class="ph-fill ph-star" data-value="2"></i>
            <i class="ph-fill ph-star" data-value="3"></i>
            <i class="ph-fill ph-star" data-value="4"></i>
            <i class="ph-fill ph-star" data-value="5"></i>
        </div>

        <button class="end-trip-btn" onclick="alert('Trip Ended! Thank you for riding with CampusPulse.');">End
            Trip</button>
    </div>
</section>

<section id="announcements" class="announcement-section">
    <div style="max-width: 1200px; margin: 0 auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 0 20px;"
            class="reveal">
            <div>
                <h2
                    style="color:var(--primary-blue); margin:0; display: flex; align-items: center; gap: 10px; font-family:'Montserrat';">
                    <i class="fas fa-broadcast-tower" style="color: #e74c3c;"></i> Announcements
                </h2>
                <p style="color: #666; margin: 5px 0 0 0; font-size: 0.9rem;">Important news and updates.</p>
            </div>
            <a href="public_announcements.php"
                style="color:var(--primary-blue); font-weight:600; text-decoration:none;">View All <i
                    class="fas fa-arrow-right"></i></a>
        </div>

        <?php if (empty($activeAnnouncements)): ?>
            <div style="max-width: 1100px; margin: 0 auto;" class="reveal">
                <div
                    style="text-align:center; padding:60px; background:#f8f9fa; border-radius:16px; border: 2px dashed #eee;">
                    <i class="fas fa-check-circle" style="font-size: 3rem; color: #27ae60; margin-bottom: 20px;"></i>
                    <h3 style="color: #333; margin: 0 0 5px 0; font-family:'Montserrat';">All Systems Normal</h3>
                    <p style="color:#777; margin: 0;">There are no active public alerts at the moment.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="carousel-wrapper reveal">
                <div class="carousel-track">
                    <?php
                    $carouselItems = array_merge($activeAnnouncements, $activeAnnouncements);
                    foreach ($carouselItems as $announcement):
                        $tag = $announcement['tag'] ?? '#Info';
                        $isEmergency = strpos($tag, 'Emergency') !== false || strpos($tag, 'Urgent') !== false;
                        $isWarning = strpos($tag, 'Warning') !== false || strpos($tag, 'Traffic') !== false || strpos($tag, 'Weather') !== false;

                        $weight = $isEmergency ? 3 : ($isWarning ? 2 : 1);
                        $tagClass = $isEmergency ? 'tag-emergency' : ($isWarning ? 'tag-warning' : 'tag-info');
                        $cardClass = $isEmergency ? 'card-emergency' : ($isWarning ? 'card-warning' : 'card-info');

                        if ($isEmergency) {
                            $icon = '<i class="fas fa-exclamation-circle"></i>';
                        } elseif ($isWarning) {
                            $icon = '<i class="fas fa-bolt"></i>';
                        } else {
                            $icon = '<i class="fas fa-info-circle"></i>';
                        }

                        $msgFontSize = ($weight === 2) ? '0.85rem' : '0.95rem';
                        ?>
                        <div class="announcement-card <?= $cardClass ?>">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <span class="feed-tag <?= $tagClass ?>"><?= $icon ?>         <?= htmlspecialchars($tag) ?></span>
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
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($announcement['location_name']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<section id="join-fleet" style="padding: 80px 20px; background: #0A3060; color: white; text-align: center;">
    <div style="max-width: 800px; margin: 0 auto;" class="reveal">
        <h2 style="font-size: 2.2rem; margin-bottom: 15px; color: white; font-family:'Montserrat';">Drive with
            CampusPulse</h2>
        <p style="font-size: 1.1rem; opacity: 0.9; margin-bottom: 30px;">
            Help fellow students commute safely while earning. Join our fleet of official university shuttle drivers
            today.
        </p>
        <a href="driver_application/driver_application.php" class="store-btn"
            style="display: inline-flex; width: auto; font-weight: 600; padding: 15px 30px; font-size: 1.1rem; justify-content: center;">
            <i class="fas fa-car-side"></i> Apply to Become a Driver
        </a>
    </div>
    <hr style="width: 100%; margin: 60px auto; border: 0; border-top: 1px solid #e0e7ff;">
</section>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const reveals = document.querySelectorAll('.reveal, .fade-up');
        const revealOptions = {
            threshold: 0.15,
            rootMargin: "0px 0px -50px 0px"
        };

        const revealOnScroll = new IntersectionObserver(function (entries, observer) {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('active');
                observer.unobserve(entry.target);
            });
        }, revealOptions);

        reveals.forEach(reveal => {
            revealOnScroll.observe(reveal);
        });

        const counterElement = document.getElementById('shuttleCounter');
        if (counterElement) {
            const target = parseInt(counterElement.getAttribute('data-target'));
            let current = 0;
            const duration = 1500;
            const stepTime = Math.abs(Math.floor(duration / (target || 1)));

            if (target > 0) {
                const timer = setInterval(() => {
                    current += 1;
                    counterElement.innerText = current;
                    if (current >= target) {
                        clearInterval(timer);
                    }
                }, stepTime);
            }
        }

        if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
            gsap.to('.timeline-progress', {
                height: '100%',
                ease: 'none',
                scrollTrigger: {
                    trigger: '.timeline-section',
                    start: "top 50%",
                    end: "bottom 50%",
                    scrub: 0.1
                }
            });

            gsap.utils.toArray('.timeline-node').forEach(node => {
                ScrollTrigger.create({
                    trigger: node,
                    start: "top 50%",
                    toggleClass: "active"
                });
            });

            gsap.to('.gs-bus', {
                left: "70%",
                duration: 3,
                ease: "power1.inOut",
                yoyo: true,
                repeat: -1
            });
        }

        const stars = document.querySelectorAll('#interactive-stars i');
        let currentRating = 5;

        function updateStars(val) {
            stars.forEach(s => {
                if (s.getAttribute('data-value') <= val) {
                    s.classList.remove('inactive');
                } else {
                    s.classList.add('inactive');
                }
            });
        }

        stars.forEach(star => {
            star.addEventListener('mouseover', function () {
                let val = this.getAttribute('data-value');
                updateStars(val);
            });
            star.addEventListener('mouseout', function () {
                updateStars(currentRating);
            });
            star.addEventListener('click', function () {
                currentRating = this.getAttribute('data-value');
                updateStars(currentRating);
            });
        });

    });
</script>

<?php include $depth . 'layout/public/footer.php'; ?>