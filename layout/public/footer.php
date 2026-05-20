<?php
// Allow pages to hide the visual footer but still load Firebase & closing tags
$hideVisualFooter = isset($hideFooter) ? $hideFooter : false;

if (!$hideVisualFooter):
    ?>
    <footer class="main-footer">
        <div class="footer-content">
            <div class="footer-logo">
                <h3>Campus<span style="color:var(--accent-yellow)">Pulse</span></h3>
                <p>Smart Transportation for UniKL</p>
            </div>
            <div class="footer-links">
                <a href="<?= $path ?? '' ?>terms.php" target="_blank">Passenger Terms of Use & Service Policy</a>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> CampusPulse. Final Year Project.</p>
        </div>
    </footer>
<?php endif; ?>

<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging-compat.js"></script>

<script>
    console.log("1. Script Loaded");

    const firebaseConfig = {
        apiKey: "<?= MAPS_API_KEY ?>",
        authDomain: "<?= FIREBASE_AUTH_DOMAIN ?>",
        projectId: "<?= FIREBASE_PROJECT_ID ?>",
        storageBucket: "<?= FIREBASE_STORAGE_BUCKET ?>",
        messagingSenderId: "<?= FIREBASE_MESSAGING_SENDER_ID ?>",
        appId: "<?= FIREBASE_APP_ID ?>"
    };

    if (!firebase.apps.length) {
        firebase.initializeApp(firebaseConfig);
        console.log("2. Firebase Initialized");
    }

    const messaging = firebase.messaging();

    function initNotifications() {
        console.log("3. initNotifications() triggered");

        Notification.requestPermission().then((permission) => {
            console.log("4. Permission Status:", permission);
            if (permission === 'granted') {
                const myVapidKey = 'BAlUeulOYhdHbGLNSzFn9R2OYuCjFeWv3C5GlV5oH_D8ejd4mQgkipB2fReDX5VrmhsF876gacsjcHgO-Z8llQk';

                messaging.getToken({ vapidKey: myVapidKey })
                    .then((token) => {
                        console.log("5. Token Received:", token);
                        fetch('<?= $path ?? '' ?>subscribe_fcm.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ token: token, topic: 'all' })
                        }).then(res => console.log("6. PHP Subscription Done"));
                    }).catch(err => console.error("Token Error:", err));
            }
        });
    }
    initNotifications();
</script>
</body>

</html>