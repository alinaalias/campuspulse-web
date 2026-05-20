<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}
$driverId = $_SESSION['user_id'];

// Fetch Ratings Data
$reviews = [];
$totalScore = 0;
$count = 0;
$starCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

$query = $firestore->database()->collection('Ratings')
    ->where('driver_id', '=', $driverId)
    ->orderBy('timestamp', 'DESC')
    ->documents();

foreach ($query as $doc) {
    if (!$doc->exists())
        continue;
    $d = $doc->data();

    // Calculate Score & Stars
    $score = (float) ($d['rating'] ?? 0);
    $totalScore += $score;
    $count++;

    $roundedScore = (int) round($score);
    if ($roundedScore >= 1 && $roundedScore <= 5) {
        $starCounts[$roundedScore]++;
    }

    // Format Timestamp (Handles both String and Firestore Object)
    $ts = $d['timestamp'] ?? time();
    if (is_object($ts) && method_exists($ts, 'get')) {
        $dateStr = $ts->get()->format('d M Y');
    } elseif (is_string($ts)) {
        $dateStr = date('d M Y', strtotime($ts));
    } else {
        $dateStr = date('d M Y', $ts);
    }

    // Fetch Passenger Name (Masked for privacy, e.g., "Ahmad M.")
    $studentName = "Passenger";
    if (!empty($d['user_id'])) {
        try {
            $stSnap = $firestore->database()->collection('Students')->document($d['user_id'])->snapshot();
            if ($stSnap->exists()) {
                $fullName = $stSnap->data()['full_name'] ?? "Passenger";
                $parts = explode(' ', $fullName);
                if (count($parts) > 1) {
                    $studentName = $parts[0] . ' ' . substr($parts[1], 0, 1) . '.';
                } else {
                    $studentName = $parts[0];
                }
            }
        } catch (Exception $e) {
        }
    }

    // Only add to the visible list if there's a comment or tags
    $comment = trim($d['comment'] ?? '');
    $tags = $d['feedback_tags'] ?? [];

    if (!empty($comment) || !empty($tags)) {
        $reviews[] = [
            'student' => $studentName,
            'rating' => $score,
            'comment' => $comment,
            'tags' => is_array($tags) ? $tags : [],
            'date' => $dateStr
        ];
    }
}

// Calculate Final Average
$average = ($count > 0) ? round($totalScore / $count, 1) : 0.0;


if ($count > 0) {
    try {
        $firestore->database()->collection('Staffs')->document($driverId)->update([
            ['path' => 'rating', 'value' => $average],
            ['path' => 'total_ratings', 'value' => $count]
        ]);
    } catch (Exception $e) {
    }
}
$pageTitle = 'My Ratings';
$extraHead = '
<style>
    .driver-header {
        padding: 30px 20px 60px 20px;
    }

    .rating-container {
        margin-top: -30px;
        padding: 0 20px 100px 20px;
        position: relative;
        z-index: 10;
    }

    .summary-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
        margin-bottom: 25px;
    }

    .score-hero { display: flex; align-items: center; justify-content: center; gap: 20px; margin-bottom: 25px; }
    .big-score { font-size: 4rem; font-weight: 700; color: #2d3748; line-height: 1; }
    .stars-hero i { color: #f39c12; font-size: 1.5rem; margin-right: 2px; }
    .stars-hero i.empty { color: #e2e8f0; }

    .breakdown-row { display: flex; align-items: center; margin-bottom: 8px; font-size: 0.85rem; color: #718096; font-weight: 600; }
    .breakdown-bar-bg { flex: 1; height: 8px; background: #edf2f7; border-radius: 10px; margin: 0 15px; overflow: hidden; }
    .breakdown-bar-fill { height: 100%; background: #f39c12; border-radius: 10px; }

    .section-title { font-size: 1.1rem; font-weight: 700; color: #2d3748; margin-bottom: 15px; padding-left: 5px; }

    .review-card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); border: 1px solid #f1f5f9; }
    .review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .reviewer-info { display: flex; align-items: center; gap: 10px; }
    .reviewer-avatar { width: 35px; height: 35px; background: #edf2f7; color: #a0aec0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
    .reviewer-name { font-weight: 600; color: #2d3748; font-size: 0.95rem; }
    .review-date { font-size: 0.75rem; color: #a0aec0; font-weight: 500; }
    .review-stars { color: #f39c12; font-size: 0.85rem; margin-bottom: 10px; }
    .review-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
    .tag-pill { background: #ebf5fb; color: #2980b9; font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
    .review-comment { font-size: 0.9rem; color: #4a5568; line-height: 1.5; font-style: italic; background: #f8fafc; padding: 12px; border-radius: 10px; border-left: 3px solid #cbd5e0; }
</style>';
include '../layout/driver/header.php';
?>

    <div class="driver-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <a href="driver_profile.php" style="color: white; font-size: 1.2rem;"><i class="fas fa-arrow-left"></i></a>
            <div>
                <div
                    style="font-size: 0.75rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 2px;">
                    Driver Performance</div>
                <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; line-height: 1;">My Ratings</h2>
            </div>
        </div>
    </div>

    <div class="rating-container">

        <div class="summary-card">
            <div class="score-hero">
                <div class="big-score"><?= number_format($average, 1) ?></div>
                <div>
                    <div class="stars-hero">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= ($i <= round($average)) ? '' : 'empty' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div style="color: #718096; font-size: 0.85rem; font-weight: 500; margin-top: 5px;">
                        Based on <?= $count ?> total trips
                    </div>
                </div>
            </div>

            <div style="border-top: 1px solid #edf2f7; padding-top: 20px;">
                <?php for ($star = 5; $star >= 1; $star--):
                    $percentage = ($count > 0) ? ($starCounts[$star] / $count) * 100 : 0;
                    ?>
                    <div class="breakdown-row">
                        <div style="width: 15px; text-align: center;"><?= $star ?></div>
                        <i class="fas fa-star" style="color: #a0aec0; font-size: 0.7rem; margin-left: 5px;"></i>
                        <div class="breakdown-bar-bg">
                            <div class="breakdown-bar-fill" style="width: <?= $percentage ?>%;"></div>
                        </div>
                        <div style="width: 25px; text-align: right;"><?= $starCounts[$star] ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="section-title">Passenger Feedback</div>

        <?php if (empty($reviews)): ?>
            <div
                style="text-align: center; padding: 40px 20px; color: #a0aec0; background: white; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                <i class="fas fa-comment-slash" style="font-size: 3rem; margin-bottom: 15px; color: #cbd5e0;"></i>
                <h3 style="margin: 0 0 5px; color: #4a5568;">No Feedback Yet</h3>
                <p style="margin: 0; font-size: 0.9rem;">Your stars and reviews will appear here after you complete your
                    trips!</p>
            </div>
        <?php else: ?>

            <?php foreach ($reviews as $r): ?>
                <div class="review-card">
                    <div class="review-header">
                        <div class="reviewer-info">
                            <div class="reviewer-avatar"><i class="fas fa-user"></i></div>
                            <div class="reviewer-name"><?= $r['student'] ?></div>
                        </div>
                        <div class="review-date"><?= $r['date'] ?></div>
                    </div>

                    <div class="review-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= ($i <= $r['rating']) ? '' : 'empty' ?>"
                                style="<?= ($i > $r['rating']) ? 'color:#e2e8f0;' : '' ?>"></i>
                        <?php endfor; ?>
                    </div>

                    <?php if (!empty($r['tags'])): ?>
                        <div class="review-tags">
                            <?php foreach ($r['tags'] as $tag): ?>
                                <div class="tag-pill"><i class="fas fa-check-circle" style="margin-right:3px;"></i>
                                    <?= htmlspecialchars($tag) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($r['comment'])): ?>
                        <div class="review-comment">"<?= $r['comment'] ?>"</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div style="text-align: center; padding: 20px; color: #a0aec0; font-size: 0.8rem; font-weight: 500;">
                <i class="fas fa-shield-alt"></i> Reviews are anonymous to protect passenger privacy.
            </div>

        <?php endif; ?>

    </div>

<?php include '../layout/driver/footer.php'; ?>