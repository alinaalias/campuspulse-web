<?php
$path = isset($depth) ? $depth : '../';
?>

<nav class="top-navbar">

    <button type="button" id="sidebarToggle" class="btn-toggle">
        <i class="fas fa-bars"></i>
    </button>

    <div style="flex: 1;"></div>

    <div class="user-profile">
        <a href="<?= $path ?>admin/profile.php"
            style="display:flex; align-items:center; gap:10px; text-decoration:none;">
            <div style="text-align: right;">
                <span style="display:block; font-weight:600; font-size:0.9rem; color:var(--primary-blue);">
                    <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?>
                </span>
                <span style="display:block; font-size:0.75rem; color:#888;">Logged In</span>
            </div>

            <?php
            if (!empty($_SESSION['profile_pic'])) {
                $photoSrc = $_SESSION['profile_pic'];
            } else {
                $photoSrc = $path . 'img/default-avatar.jpg';
            }
            ?>
            <img src="<?= $photoSrc ?>" alt="Profile" class="user-avatar" style="object-fit: cover;">
        </a>

        <a href="<?= $path ?>logout.php" title="Logout" style="color: var(--danger); margin-left: 10px;">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>

</nav>

<script src="<?= $path ?>js/admin.js"></script>