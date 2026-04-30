<?php
require_once '../config.php';
session_start();
// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$pageTitle = 'Admin Dashboard - CampusPulse';
include '../layout/admin_header.php';
?>


<h2 class="page-title">Dashboard Overview</h2>

<div class="dashboard-grid">

    <a href="live_operations.php" class="card dashboard-card">
        <div class="icon-box blue">
            <i class="fas fa-tower-broadcast"></i>
        </div>
        <div class="card-info">
            <h3>Live Operations</h3>
            <p>View live operations</p>
        </div>
    </a>

    <a href="admin_review_drivers.php" class="card dashboard-card">
        <div class="icon-box yellow">
            <i class="fas fa-id-card"></i>
        </div>
        <div class="card-info">
            <h3>Review Drivers Applications</h3>
            <p>Review drivers applications</p>
        </div>
    </a>

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

    <?php include '../layout/admin_footer.php'; ?>