<?php
require_once '../config.php';
session_start();
// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CampusPulse</title>
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <link rel="stylesheet" href="../css/style.css"> 
</head>
<body>

    <div class="wrapper">
        <?php $depth = '../'; ?>
        <?php include '../layout/sidebar.php'; ?>

        <div id="content">
            
            <?php include '../layout/header.php'; ?>

            <div class="main-content">
                
                <h2 class="page-title">Dashboard Overview</h2>

                <div class="dashboard-grid">

                    <a href="route_management/routes_management.php" class="card dashboard-card">
                        <div class="icon-box blue">
                            <i class="fas fa-map-signs"></i>
                        </div>
                        <div class="card-info">
                            <h3>Routes</h3>
                            <p>Manage zones & stops</p>
                        </div>
                    </a>

                    <a href="shuttle_management/shuttles_management.php" class="card dashboard-card">
                        <div class="icon-box yellow">
                            <i class="fas fa-bus"></i>
                        </div>
                        <div class="card-info">
                            <h3>Shuttles</h3>
                            <p>Manage fleet status</p>
                        </div>
                    </a>

                    <a href="driver_management/drivers_management.php" class="card dashboard-card">
                        <div class="icon-box blue">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="card-info">
                            <h3>Drivers</h3>
                            <p>Assign drivers</p>
                        </div>
                    </a>

                    <a href="schedule_management/schedules_management.php" class="card dashboard-card">
                        <div class="icon-box yellow">
                            <i class="far fa-calendar-alt"></i>
                        </div>
                        <div class="card-info">
                            <h3>Schedules</h3>
                            <p>Daily trips</p>
                        </div>
                    </a>

                    <a href="booking_management/bookings_management.php" class="card dashboard-card">
                        <div class="icon-box blue">
                            <i class="far fa-bookmark"></i>
                        </div>
                        <div class="card-info">
                            <h3>Bookings</h3>
                            <p>Schedule and OnDemand bookings</p>
                        </div>
                    </a>

                    <a href="student_management/students_management.php" class="card dashboard-card">
                        <div class="icon-box yellow">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="card-info">
                            <h3>Students</h3>
                            <p>View registered students</p>
                        </div>
                    </a>

                    <a href="announcement/announcements_management.php" class="card dashboard-card">
                        <div class="icon-box blue">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <div class="card-info">
                            <h3>Announcements</h3>
                            <p>Post updates & news</p>
                        </div>
                    </a>

                </div> </div>
        </div>
    </div>
</body>
</html>