<?php
require_once 'config.php'; 
session_start();

// Detect login & role
$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $_SESSION['role'] ?? null;

// ==========================================
// 1. FETCH PUBLIC ANNOUNCEMENTS
// ==========================================
$announcementsRef = $firestore->database()
    ->collection('Announcements')
    ->where('status', '=', 'sent') 
    ->documents();

$activeAnnouncements = [];
$currentTime = time(); 

foreach ($announcementsRef as $doc) {
    $data = $doc->data();
    
    $target = $data['target_audience'] ?? 'all';
    if ($target === 'driver') {
        continue; 
    }

    if (!empty($data['schedule_time'])) {
        $scheduledTimestamp = strtotime($data['schedule_time']);
        if ($scheduledTimestamp > $currentTime) {
            continue; 
        }
        $data['timestamp'] = $scheduledTimestamp; 
    } else {
        $data['timestamp'] = strtotime($data['created_at'] ?? 'now');
    }

    $activeAnnouncements[] = $data;
}

usort($activeAnnouncements, function ($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

$activeAnnouncements = array_slice($activeAnnouncements, 0, 6);
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
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #003366 0%, #004080 100%);
            color: white;
            padding: 80px 20px 100px 20px;
            text-align: center;
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
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }
        .hero h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
        }
        .hero p {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 40px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }
        .hybrid-badge {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
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
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .store-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        .store-text { text-align: left; line-height: 1.2; }
        .store-text span { display: block; font-size: 0.75rem; color: #666; }
        .store-text strong { font-size: 1.1rem; color: #000; }

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
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-color: var(--primary-blue);
        }
        .rec-icon {
            width: 60px; height: 60px;
            background: #e3f2fd;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 20px;
            color: var(--primary-blue);
            font-size: 1.5rem;
        }
        .rec-badge {
            position: absolute; top: 20px; right: 20px;
            background: #fff3cd; color: #856404;
            padding: 5px 10px; border-radius: 10px;
            font-size: 0.75rem; font-weight: 700;
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
            box-shadow: 0 5px 20px rgba(0,0,0,0.03);
            text-align: center;
            height: 100%;
        }

        /* Announcements */
        .announcement-section {
            padding: 80px 20px;
            background: white;
        }
        .announcement-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
            transition: all 0.2s;
        }
        .announcement-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 { font-size: 2.2rem; }
            .store-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<?php include 'layout/public_header.php'; ?>

<section class="hero" id="download-section">
    <div class="hero-content">
        <div class="hybrid-badge">
            <i class="fas fa-sync-alt"></i> Hybrid Mobility System
        </div>
        <h1>Your Campus Commute,<br>Reimagined.</h1>
        <p>Experience the flexibility of a <strong>Hybrid Shuttle Network</strong>. Whether you prefer a fixed schedule or need an on-demand ride, CampusPulse adapts to your student lifestyle.</p>
        
        <div class="app-badges">
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
        </div>

        <div style="margin-top: 20px;">
            <a href="shuttle_schedule.php" style="color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.9rem; border-bottom: 1px dashed rgba(255,255,255,0.5);">
                View web schedule <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>

<section class="smart-rec-section">
    <div style="max-width: 800px; margin: 0 auto;">
        <h2 style="color:var(--primary-blue); font-size: 2.2rem; margin-bottom: 10px;">Smart Travel Assistant</h2>
        <p style="color:#666;">The CampusPulse app uses modern features for students comfortness.</p>
    </div>

    <div class="rec-grid">
        <div class="rec-card">
            <div class="rec-badge">✨ Smart</div>
            <div class="rec-icon"><i class="fas fa-route"></i></div>
            <h3 style="margin-bottom: 10px; color:#333;">Smart Recommendation</h3>
            <p style="color:#666; line-height: 1.6;">Based on your class timetable, the app suggests the fastest shuttle route before you even ask.</p>
        </div>

        <div class="rec-card">
            <div class="rec-badge">⚡ Real-Time</div>
            <div class="rec-icon"><i class="fas fa-hourglass-half"></i></div>
            <h3 style="margin-bottom: 10px; color:#333;">Wait-Time Prediction</h3>
            <p style="color:#666; line-height: 1.6;">Know exactly when the next bus arrives with predictive ETAs based on live traffic data.</p>
        </div>

        <div class="rec-card">
            <div class="rec-badge">💎 Premium</div>
            <div class="rec-icon"><i class="fas fa-star"></i></div>
            <h3 style="margin-bottom: 10px; color:#333;">Personalized Alerts</h3>
            <p style="color:#666; line-height: 1.6;">Get notified only for the routes you use. Rain delays? Traffic jams? You'll know first.</p>
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
                <div style="background:#e3f2fd; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto;">
                    <i class="far fa-calendar-check fa-2x" style="color:var(--primary-blue);"></i>
                </div>
                <h3>1. Scheduled Service</h3>
                <p style="color:#666;">Perfect for daily classes. Reliable, fixed timings ensure you never miss a lecture during peak hours.</p>
            </div>

            <div class="feature-box">
                <div style="background:#e3f2fd; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto;">
                    <i class="fas fa-hand-pointer fa-2x" style="color:var(--primary-blue);"></i>
                </div>
                <h3>2. On-Demand Rides</h3>
                <p style="color:#666;">Need a ride during off-peak hours? Book a shuttle instantly through the app, just like e-hailing.</p>
            </div>

            <div class="feature-box">
                <div style="background:#e3f2fd; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px auto;">
                    <i class="fas fa-mobile-alt fa-2x" style="color:var(--primary-blue);"></i>
                </div>
                <h3>3. Live Tracking</h3>
                <p style="color:#666;">Watch your shuttle move on the map in real-time. No more guessing games at the bus stop.</p>
            </div>
        </div>
    </div>
</section>

<section id="announcements" class="announcement-section">
    <div style="max-width: 1100px; margin: 0 auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px;">
            <h2 style="color:var(--primary-blue); margin:0;">
                <i class="fas fa-bullhorn"></i> Campus Updates
            </h2>
            <a href="#" style="color:var(--primary-blue); font-weight:600; text-decoration:none;">View All <i class="fas fa-arrow-right"></i></a>
        </div>

        <?php if (empty($activeAnnouncements)): ?>
            <div style="text-align:center; padding:60px; background:#f8f9fa; border-radius:16px;">
                <i class="far fa-folder-open" style="font-size: 3rem; color: #ddd; margin-bottom: 20px;"></i>
                <p style="color:#777;">No active announcements at the moment.</p>
            </div>
        <?php else: ?>
            <div class="announcement-grid">
                <?php foreach ($activeAnnouncements as $announcement): ?>
                    <div class="announcement-card">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                            <h3 style="margin:0; font-size:1.1rem; color:#333; font-weight:700;">
                                <?= htmlspecialchars($announcement['title']) ?>
                            </h3>
                            <small style="color:#999; white-space:nowrap; font-size:0.8rem;">
                                <?= isset($announcement['schedule_time']) ? date('d M', strtotime($announcement['schedule_time'])) : 'Today' ?>
                            </small>
                        </div>
                        <p style="color:#555; line-height:1.6; margin:0; font-size:0.95rem;">
                            <?= nl2br(htmlspecialchars($announcement['message'])) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include 'layout/footer.php'; ?>

</body>
</html>