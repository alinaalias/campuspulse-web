<?php
session_start();
require_once '../config.php';

// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'driver') {
    header('Location: ../login.php');
    exit();
}

$alerts = [];

try {
    // 2. Fetch Alerts
    $docs = $firestore->database()->collection('Announcements')
        ->orderBy('created_at', 'DESC')
        ->documents();

    foreach ($docs as $doc) {
        $data = $doc->data();
        $audience = $data['target_audience'] ?? 'all';
        $status = $data['status'] ?? 'sent'; // Default to 'sent' if missing

        // --- THE FIX IS HERE ---
        // We now accept 'published' OR 'sent'
        $isActive = ($status === 'published' || $status === 'sent');
        $isRelevant = ($audience === 'driver' || $audience === 'all');

        if ($isActive && $isRelevant) {
            $data['id'] = $doc->id();
            
            // Time Formatting
            $ts = isset($data['created_at']) ? strtotime($data['created_at']) : time();
            $diff = time() - $ts;
            
            if ($diff < 60) {
                $data['relative_time'] = 'Just now';
            } elseif ($diff < 3600) {
                $data['relative_time'] = floor($diff / 60) . ' mins ago';
            } elseif ($diff < 86400) {
                $data['relative_time'] = floor($diff / 3600) . ' hours ago';
            } else {
                $data['relative_time'] = floor($diff / 86400) . ' days ago';
            }
            
            $alerts[] = $data;
        }
    }
} catch (Exception $e) {
    $alerts = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Alerts</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    
    <style>
        body { background-color: #f4f6f9; min-height: 100vh; display: flex; flex-direction: column; }
        
        .alerts-header {
            background: var(--primary-blue);
            color: white;
            padding: 25px 20px 70px 20px;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            position: relative;
            z-index: 1;
        }

        .alerts-container {
            margin-top: -50px;
            padding: 0 20px 100px 20px;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .alert-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 5px solid #ddd;
            position: relative;
            overflow: hidden;
        }

        /* Severity Variations */
        .alert-urgent { border-left-color: #dc3545; }
        .alert-urgent .icon-bg { background: #ffe6e6; color: #dc3545; }
        
        .alert-info { border-left-color: #17a2b8; }
        .alert-info .icon-bg { background: #e0f7fa; color: #17a2b8; }
        
        .alert-general { border-left-color: var(--primary-blue); }
        .alert-general .icon-bg { background: #e3f2fd; color: var(--primary-blue); }

        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .icon-bg {
            width: 35px; height: 35px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .alert-title { font-size: 0.95rem; font-weight: 700; color: #333; }
        .alert-time { font-size: 0.75rem; color: #999; white-space: nowrap; margin-left: 10px; }
        .alert-body { font-size: 0.9rem; color: #666; line-height: 1.5; padding-left: 47px; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #aaa;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
    </style>
</head>
<body>

    <div class="alerts-header">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h2 style="margin:0; font-size:1.4rem;">Notifications</h2>
                <p style="margin:5px 0 0 0; opacity:0.8; font-size:0.9rem;">Admin announcements & alerts</p>
            </div>
            <div style="background:rgba(255,255,255,0.2); width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-bell"></i>
            </div>
        </div>
    </div>

    <div class="alerts-container">
        
        <?php if (empty($alerts)): ?>
             <div class="empty-state">
                <i class="far fa-folder-open" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                <p style="margin:0; font-weight:500;">No new announcements</p>
            </div>
        <?php else: ?>
            
            <?php foreach ($alerts as $alert): 
                $type = $alert['type'] ?? 'general'; 
                $styleClass = 'alert-general';
                $icon = 'fa-info';

                // Basic logic to pick icon based on Title keywords (since 'type' is missing in your DB screenshot)
                $titleLower = strtolower($alert['title']);
                if (str_contains($titleLower, 'urgent') || str_contains($titleLower, 'alert') || str_contains($titleLower, 'warning')) {
                    $styleClass = 'alert-urgent';
                    $icon = 'fa-exclamation-triangle';
                } elseif (str_contains($titleLower, 'maintenance') || str_contains($titleLower, 'info')) {
                    $styleClass = 'alert-info';
                    $icon = 'fa-info-circle';
                }
            ?>
            <div class="alert-card <?= $styleClass ?>">
                <div class="alert-header">
                    <div style="display:flex; align-items:center;">
                        <div class="icon-bg"><i class="fas <?= $icon ?>"></i></div>
                        <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
                    </div>
                    <span class="alert-time"><?= $alert['relative_time'] ?></span>
                </div>
                <div class="alert-body">
                    <?= nl2br(htmlspecialchars($alert['message'])) ?>
                </div>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <?php include 'driver_navbar.php'; ?>

</body>
</html>