<footer class="main-footer">
    <div class="footer-content">
        <div class="footer-logo">
            <h3>Campus<span style="color:var(--accent-yellow)">Pulse</span></h3>
            <p>Smart Transportation for UniKL</p>
        </div>
        <div class="footer-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Support</a>
        </div>
    </div>

    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-messaging-compat.js"></script>

    <script>
        console.log("1. Script Loaded");

        const firebaseConfig = {
            apiKey: "AIzaSyD_E8JfmScnhsxqW-sBCOfW8kRFdNcrGIk",
            authDomain: "campuspulse-bfd09.firebaseapp.com",
            projectId: "campuspulse-bfd09",
            storageBucket: "campuspulse-bfd09.firebasestorage.app",
            messagingSenderId: "380453135946",
            appId: "1:380453135946:web:00e83d9df74b17c19ba8b3"
        };

        if (!firebase.apps.length) {
            firebase.initializeApp(firebaseConfig);
            console.log("2. Firebase Initialized");
        }

        const messaging = firebase.messaging();

        // FORCE TRIGGER FUNCTION
        function initNotifications() {
            console.log("3. initNotifications() triggered");

            Notification.requestPermission().then((permission) => {
                console.log("4. Permission Status:", permission);

                if (permission === 'granted') {
                    // REPLACE THIS WITH YOUR ACTUAL VAPID KEY FROM FIREBASE CONSOLE
                    const myVapidKey = 'BAlUeulOYhdHbGLNSzFn9R2OYuCjFeWv3C5GlV5oH_D8ejd4mQgkipB2fReDX5VrmhsF876gacsjcHgO-Z8llQk';

                    messaging.getToken({ vapidKey: myVapidKey })
                        .then((token) => {
                            console.log("5. Token Received:", token);

                            // Send to PHP
                            fetch('subscribe_fcm.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ token: token, topic: 'all' })
                            }).then(res => console.log("6. PHP Subscription Done"));

                        }).catch(err => console.error("Token Error:", err));
                }
            });
        }

        // Auto-run for testing
        initNotifications();
    </script>
    <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> CampusPulse. Final Year Project.</p>
    </div>
</footer>