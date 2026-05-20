<?php $path = isset($depth) ? $depth : '../'; ?>
<?php $hideNav = isset($hideNavbar) ? $hideNavbar : false; ?>
<?php $skipFb = isset($skipFirebase) ? $skipFirebase : false; ?>

<?php if (!$hideNav): ?>
    <?php include $path . 'driver/components/driver_navbar.php'; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (!$skipFb): ?>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>

<script>
    const firebaseConfig = {
        apiKey: "<?= MAPS_API_KEY ?? '' ?>",
        authDomain: "<?= FIREBASE_AUTH_DOMAIN ?? '' ?>",
        projectId: "<?= FIREBASE_PROJECT_ID ?? '' ?>",
        storageBucket: "<?= FIREBASE_STORAGE_BUCKET ?? '' ?>",
        messagingSenderId: "<?= FIREBASE_MESSAGING_SENDER_ID ?? '' ?>",
        appId: "<?= FIREBASE_APP_ID ?? '' ?>"
    };

    if (!firebase.apps.length) {
        firebase.initializeApp(firebaseConfig);
    }

    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        console.warn('[CampusPulse] Push Notifications require HTTPS.');
    }

    function firePushNotification(title, body, url = 'driver_notifications.php') {
        if (Notification.permission !== 'granted') return;
        try {
            let notif = new Notification(title, { body: body, icon: '<?= $path ?>img/favicon.ico' });
            notif.onclick = function () { window.location.href = url; };
            if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
        } catch (e) {
            console.warn('[CampusPulse] Notification error:', e);
        }
    }

    function requestPushPermissions() {
        if (!('Notification' in window)) return;
        Notification.requestPermission().then(permission => {
            if (permission === 'granted') {
                document.getElementById('pushPrompt').style.display = 'none';
                console.log('[CampusPulse] Push notifications enabled.');
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        if ('Notification' in window && Notification.permission === 'default') {
            const prompt = document.getElementById('pushPrompt');
            if (prompt) prompt.style.display = 'block';
        }
    });
</script>
<?php endif; ?>

<?php if (isset($extraScripts))
    echo $extraScripts; ?>
</body>

</html>