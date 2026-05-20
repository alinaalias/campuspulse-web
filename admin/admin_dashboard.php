<?php
require_once '../config.php';
session_start();
// 1. Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$pageTitle = 'Admin Dashboard - CampusPulse';
$depth = '../';
include $depth . 'layout/admin/header.php';
?>

<style>
    /* Master 2x2 Layout */
    .dashboard-master-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        /* 2 columns */
        gap: 30px;
        align-items: stretch;
    }

    /* Individual Category Container Box */
    .dashboard-category {
        background: #ffffff;
        border-radius: 12px;
        padding: 25px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        display: flex;
        flex-direction: column;
    }

    /* Category Header (Top of each box) */
    .category-header {
        margin-bottom: 20px;
        border-bottom: 2px solid #f1f5f9;
        padding-bottom: 15px;
    }

    .category-title {
        font-size: 1.1rem;
        color: #334155;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }

    .category-title i {
        color: var(--primary-blue);
        font-size: 1.3rem;
    }

    .category-desc {
        font-size: 0.85rem;
        color: #94a3b8;
        margin-top: 8px;
        line-height: 1.4;
    }

    /* Cards Grid within each Category */
    .category-cards {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
        flex: 1;
        align-content: start;
        /* Prevents the cards from stretching vertically! */
    }

    /* Inner Card Styling */
    .dashboard-card {
        padding: 20px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        gap: 12px;
        transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
    }

    .dashboard-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        background: #ffffff;
    }

    .dashboard-card h3 {
        font-size: 1rem;
        margin: 0;
        color: #1e293b;
    }

    .dashboard-card p {
        font-size: 0.8rem;
        color: #64748b;
        margin: 0;
        line-height: 1.4;
    }

    /* Mobile Responsive: Collapse to 1 column on smaller screens */
    @media (max-width: 1024px) {
        .dashboard-master-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
    <h2 class="page-title" style="margin:0;">Dashboard Overview</h2>
</div>

<!-- ================= MASTER 2x2 GRID ================= -->
<div class="dashboard-master-grid">

    <!-- ================= CATEGORY: OVERVIEW ================= -->
    <div class="dashboard-category">
        <div class="category-header">
            <h2 class="category-title"><i class="fas fa-globe"></i> Overview</h2>
            <div class="category-desc">High-level system metrics and live tracking.</div>
        </div>
        <div class="category-cards">
            <a href="analytics/main_analytics.php" class="card dashboard-card">
                <div class="icon-box blue"><i class="fas fa-chart-line"></i></div>
                <div class="card-info">
                    <h3>Analytics</h3>
                    <p>View revenue, ridership, and fleet metrics.</p>
                </div>
            </a>

            <a href="live_operations.php" class="card dashboard-card">
                <div class="icon-box yellow"><i class="fas fa-tower-broadcast"></i></div>
                <div class="card-info">
                    <h3>Live Operations</h3>
                    <p>Monitor active shuttles and real-time logs.</p>
                </div>
            </a>
        </div>
    </div>

    <!-- ================= CATEGORY: DISPATCH ================= -->
    <div class="dashboard-category">
        <div class="category-header">
            <h2 class="category-title"><i class="fas fa-route"></i> Dispatch</h2>
            <div class="category-desc">Control daily trips and ride requests.</div>
        </div>
        <div class="category-cards">
            <a href="booking_management/bookings_management.php" class="card dashboard-card">
                <div class="icon-box blue"><i class="far fa-bookmark"></i></div>
                <div class="card-info">
                    <h3>Bookings</h3>
                    <p>Manage ride requests and on-demand matching.</p>
                </div>
            </a>

            <a href="schedule_management/schedules_management.php" class="card dashboard-card">
                <div class="icon-box yellow"><i class="far fa-calendar-alt"></i></div>
                <div class="card-info">
                    <h3>Schedules</h3>
                    <p>Plan fixed-route dispatch and monitor capacity.</p>
                </div>
            </a>
        </div>
    </div>

    <!-- ================= CATEGORY: FLEET & STAFF ================= -->
    <div class="dashboard-category">
        <div class="category-header">
            <h2 class="category-title"><i class="fas fa-bus"></i> Fleet & Staff</h2>
            <div class="category-desc">Manage physical assets and personnel.</div>
        </div>
        <div class="category-cards">
            <a href="route_management/routes_management.php" class="card dashboard-card">
                <div class="icon-box blue"><i class="fas fa-map-signs"></i></div>
                <div class="card-info">
                    <h3>Routes & Zones</h3>
                    <p>Configure zones, pickup points, and transit paths.</p>
                </div>
            </a>

            <a href="shuttle_management/shuttles_management.php" class="card dashboard-card">
                <div class="icon-box yellow"><i class="fas fa-van-shuttle"></i></div>
                <div class="card-info">
                    <h3>Shuttles</h3>
                    <p>Monitor vehicle status and fleet availability.</p>
                </div>
            </a>

            <a href="driver_management/drivers_management.php" class="card dashboard-card">
                <div class="icon-box blue"><i class="fas fa-user-tie"></i></div>
                <div class="card-info">
                    <h3>Drivers</h3>
                    <p>Manage credentials, assignments, and compliance.</p>
                </div>
            </a>

            <a href="admin_review_drivers.php" class="card dashboard-card">
                <div class="icon-box yellow"><i class="fas fa-id-card"></i></div>
                <div class="card-info">
                    <h3>Review Applications</h3>
                    <p>Process and verify new driver registrations.</p>
                </div>
            </a>
        </div>
    </div>

    <!-- ================= CATEGORY: USERS & COMMS ================= -->
    <div class="dashboard-category">
        <div class="category-header">
            <h2 class="category-title"><i class="fas fa-users"></i> Users & Comms</h2>
            <div class="category-desc">Interact with the student body.</div>
        </div>
        <div class="category-cards">
            <a href="student_management/students_management.php" class="card dashboard-card">
                <div class="icon-box blue"><i class="fas fa-user-graduate"></i></div>
                <div class="card-info">
                    <h3>Students</h3>
                    <p>View passenger profiles and account status.</p>
                </div>
            </a>

            <a href="announcement/announcements_management.php" class="card dashboard-card">
                <div class="icon-box yellow"><i class="fas fa-bullhorn"></i></div>
                <div class="card-info">
                    <h3>Announcements</h3>
                    <p>Broadcast updates and alerts to the public feed.</p>
                </div>
            </a>
        </div>
    </div>

</div> <!-- End Master Grid -->

<?php include $depth . 'layout/admin/footer.php'; ?>