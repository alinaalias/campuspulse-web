<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// 1. Fetch Ratings Data
$reviews = [];
$totalScore = 0;
$count = 0;

$query = $firestore->database()->collection('Ratings')
    ->where('driver_id', '=', $driverId)
    ->orderBy('created_at', 'DESC')
    ->documents();

foreach ($query as $doc) {
    if (!$doc->exists()) continue;
    $d = $doc->data();
    
    // Add to stats
    $score = (int)($d['rating'] ?? 0);
    $totalScore += $score;
    $count++;

    // Format Date
    $ts = $d['created_at'] ?? time();
    $dateStr = is_object($ts) ? $ts->get()->format('d M Y') : date('d M Y', (is_numeric($ts) ? $ts : strtotime($ts)));

    $reviews[] = [
        'student' => $d['is_anonymous'] ? 'Anonymous Student' : ($d['student_name'] ?? 'Student'),
        'rating' => $score,
        'comment' => $d['comment'] ?? 'No comment provided.',
        'date' => $dateStr
    ];
}

// Calculate Average (Avoid division by zero)
$average = ($count > 0) ? round($totalScore / $count, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Ratings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="driver-body">

    <div class="driver-header" style="height: 140px; align-items: flex-start; padding-top: 30px;">
        <div style="width: 100%; display: flex; align-items: center; gap: 15px;">
            <a href="driver_profile.php" style="color: white; font-size: 1.2rem;"><i class="fas fa-arrow-left"></i></a>
            <h2 style="margin: 0; font-size: 1.4rem; font-weight: 600;">Feedback</h2>
        </div>
    </div>

    <div class="driver-container" style="margin-top: -60px;">
        
        <div class="rating-summary-card">
            <div class="big-score"><?= $average ?></div>
            
            <div class="star-row">
                <?php for($i=1; $i<=5; $i++): ?>
                    <i class="fas fa-star <?= ($i <= round($average)) ? 'filled' : '' ?>"></i>
                <?php endfor; ?>
            </div>
            
            <div style="color: #777; font-size: 0.9rem;">
                Based on <b><?= $count ?></b> reviews
            </div>
        </div>

        <div class="section-title" style="color:#555; text-shadow:none; margin-left:5px;">Recent Comments</div>

        <?php if (empty($reviews)): ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                <i class="far fa-comment-dots" style="font-size: 2.5rem; margin-bottom: 10px;"></i>
                <p>No reviews yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $r): ?>
                <?php $class = ($r['rating'] >= 4) ? 'good' : (($r['rating'] <= 2) ? 'bad' : ''); ?>
                
                <div class="review-card <?= $class ?>">
                    <div class="review-header">
                        <div class="reviewer-name"><?= htmlspecialchars($r['student']) ?></div>
                        <div class="review-date"><?= $r['date'] ?></div>
                    </div>
                    
                    <div style="color: var(--accent-yellow); font-size: 0.8rem; margin-bottom: 8px;">
                        <?php for($i=0; $i<$r['rating']; $i++) echo '<i class="fas fa-star"></i>'; ?>
                    </div>

                    <div class="review-text">"<?= htmlspecialchars($r['comment']) ?>"</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <?php include 'driver_navbar.php'; ?>

</body>
</html>