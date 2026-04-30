// ==========================================
// 1. STATUS TOGGLE
// ==========================================
function toggleStatus() {
    const dot = document.getElementById('statusDot');
    const text = document.getElementById('statusText');
    const isOnline = text.innerText.toUpperCase() === 'ONLINE';

    // Optimistically update UI
    const newStatus = isOnline ? 'OFFLINE' : 'ONLINE';
    text.innerText = newStatus;
    text.style.color = isOnline ? 'white' : '#2ecc71';

    dot.style.background = isOnline ? '#bdc3c7' : '#2ecc71';
    dot.style.boxShadow = isOnline ? 'none' : '0 0 8px #2ecc71';

    // Update parent pill style
    const pill = text.closest('.status-pill');
    if (pill) {
        pill.style.background = isOnline ? 'rgba(255,255,255,0.15)' : 'rgba(46, 204, 113, 0.15)';
        pill.style.border = isOnline ? '1px solid rgba(255,255,255,0.2)' : '1px solid rgba(46, 204, 113, 0.3)';
    }

    // Toggle Phase 1 UI Cards
    const offlineCard = document.getElementById('offline-card');
    const scanningCard = document.getElementById('scanning-card');
    if (offlineCard && scanningCard) {
        if (!isOnline) { // Switching to ONLINE
            offlineCard.style.display = 'none';
            scanningCard.style.display = 'block';
        } else { // Switching to OFFLINE
            offlineCard.style.display = 'block';
            scanningCard.style.display = 'none';
            // Also hide pinging card if suddenly offline
            const pingContainer = document.getElementById('pinging-card-container');
            if (pingContainer) pingContainer.innerHTML = '';
        }
    }

    // Update Firestore is_online status immediately if assigned a shuttle
    if (typeof driverAssignedShuttle !== 'undefined' && driverAssignedShuttle && typeof firebase !== 'undefined' && firebase.apps.length > 0) {
        const db = firebase.firestore();
        db.collection('Shuttles').doc(driverAssignedShuttle).set({
            is_online: !isOnline,
            last_updated: firebase.firestore.FieldValue.serverTimestamp()
        }, { merge: true }).catch(err => console.error("Firestore sync error:", err));
    }

    fetch('toggle_status.php', { method: 'POST' }).then(() => {
        // UI is updated instantly, no hard reload required.
    });
}


// ==========================================
// 2. DYNAMIC GEOLOCATION & BOTTOM SHEET
// ==========================================
function toggleReportSheet(e) {
    if (typeof isDragging !== 'undefined' && isDragging) return;

    const sheet = document.getElementById('reportSheet');
    const overlay = document.getElementById('reportOverlay');

    if (sheet.classList.contains('active')) {
        sheet.classList.remove('active');
        overlay.classList.remove('active');
        cancelReport(); // Reset the UI if they close the sheet early
    } else {
        sheet.classList.add('active');
        overlay.classList.add('active');

        // Update UI to Loading State
        document.getElementById("locationIcon").innerHTML = '<i class="fas fa-circle-notch fa-spin" style="color: #3498db;"></i>';
        document.getElementById("locationStatusText").innerText = 'Detecting precision location...';
        getLocation();
    }
}

function getLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(showPosition, showError, { enableHighAccuracy: true, timeout: 15000 });
    } else {
        document.getElementById("locationStatusText").innerText = "Geolocation not supported.";
    }
}

function showPosition(position) {
    const lat = position.coords.latitude;
    const lng = position.coords.longitude;

    document.getElementById("driver_lat").value = lat;
    document.getElementById("driver_lng").value = lng;
    document.getElementById("locationStatusText").innerText = 'Finding street name...';

    const apiUrl = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;

    fetch(apiUrl, {
        headers: {
            'Accept-Language': 'en-US,en;q=0.9',
            'User-Agent': 'CampusPulseApp/1.0'
        }
    })
        .then(response => response.json())
        .then(data => {
            const addr = data.address || {};
            const precisePoint = addr.road || addr.pedestrian || addr.neighbourhood || addr.suburb || "Unknown Street";
            const cityArea = addr.city || addr.town || addr.district || "";
            const locationString = cityArea ? `${precisePoint}, ${cityArea}` : precisePoint;

            document.getElementById("locationIcon").innerHTML = '<i class="fas fa-check-circle" style="color: #27ae60;"></i>';
            document.getElementById("locationStatusText").innerText = locationString;
            document.getElementById("detected_location").value = locationString;
        })
        .catch(error => {
            document.getElementById("locationIcon").innerHTML = '<i class="fas fa-satellite" style="color: #f39c12;"></i>';
            document.getElementById("locationStatusText").innerText = `GPS: ${lat.toFixed(4)}, ${lng.toFixed(4)}`;
            document.getElementById("detected_location").value = `GPS: ${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        });
}

function showError(error) {
    document.getElementById("locationIcon").innerHTML = `<i class="fas fa-exclamation-triangle" style="color:#f39c12;"></i>`;
    document.getElementById("locationStatusText").innerText = `Location access denied or timeout.`;
}


// ==========================================
// 3. TWO-STEP REPORTING LOGIC
// ==========================================
function selectReportType(typeValue, title, iconClass, iconColor, bgColor) {
    // 1. Set the hidden input for PHP
    document.getElementById('final_alert_type').value = typeValue;

    // 2. Update the Confirmation UI dynamically
    document.getElementById('confirmTitle').innerText = title;
    document.getElementById('confirmIcon').className = `fas ${iconClass}`;
    document.getElementById('confirmIcon').style.color = iconColor;
    document.getElementById('confirmIconBg').style.background = bgColor;

    // If emergency, make the send button Red instead of Blue
    const btnSubmit = document.getElementById('btnSubmitReport');
    if (typeValue === 'breakdown' || typeValue === 'accident') {
        btnSubmit.style.background = '#dc3545';
    } else {
        btnSubmit.style.background = '#f39c12';
    }

    // 3. Swap the views
    document.getElementById('step1-grid').style.display = 'none';
    document.getElementById('step2-confirm').style.display = 'flex';
}

function cancelReport() {
    // Swap back to the grid
    document.getElementById('step2-confirm').style.display = 'none';
    document.getElementById('step1-grid').style.display = 'grid';
    document.getElementById('final_alert_type').value = '';
    document.getElementById('alert_details').value = ''; // clear text
}

function showSendingFinal(btn) {
    btn.style.opacity = '0.7';
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
}


// ==========================================
// 4. DRAGGABLE FLOATING BUTTON
// ==========================================
const fab = document.getElementById('draggableFab');
let isFabDragging = false;
let fabStartY, fabStartTop; // Renamed to avoid clashing with preview_modal.php

const dragStart = (e) => {
    if (!fab) return;
    isFabDragging = false;
    let clientY = e.type.includes('mouse') ? e.clientY : e.touches[0].clientY;
    fabStartY = clientY;

    const rect = fab.getBoundingClientRect();
    fabStartTop = rect.top;

    fab.style.bottom = 'auto';
    fab.style.top = fabStartTop + 'px';
    fab.style.transition = 'none';
};

const dragMove = (e) => {
    if (fabStartY === undefined || !fab) return;

    let clientY = e.type.includes('mouse') ? e.clientY : e.touches[0].clientY;
    let deltaY = clientY - fabStartY;

    if (Math.abs(deltaY) > 5) {
        isFabDragging = true;
        let newTop = fabStartTop + deltaY;

        const maxY = window.innerHeight - fab.offsetHeight - 80;
        const minY = 20;
        newTop = Math.max(minY, Math.min(newTop, maxY));

        fab.style.top = newTop + 'px';
        e.preventDefault();
    }
};

const dragEnd = (e) => {
    if (!fab) return;
    fabStartY = undefined;
    fab.style.transition = 'transform 0.2s';
    setTimeout(() => { isFabDragging = false; }, 50);
};

if (fab) {
    fab.addEventListener('touchstart', dragStart, { passive: false });
    fab.addEventListener('touchmove', dragMove, { passive: false });
    fab.addEventListener('touchend', dragEnd);
    fab.addEventListener('mousedown', dragStart);
    document.addEventListener('mousemove', dragMove);
    document.addEventListener('mouseup', dragEnd);
}


// ==========================================
// 5. FIREBASE PUSH NOTIFICATIONS
// ==========================================
// Ensure these run only after Firebase is loaded
document.addEventListener("DOMContentLoaded", function () {
    if (typeof firebase !== 'undefined' && firebase.apps.length > 0) {
        const messaging = firebase.messaging();

        function requestNotificationPermission() {
            Notification.requestPermission().then((permission) => {
                if (permission === 'granted') {
                    console.log('✅ Notification permission granted.');

                    if ('serviceWorker' in navigator) {
                        navigator.serviceWorker.register('../firebase-messaging-sw.js')
                            .then(function (registration) {
                                return messaging.getToken({
                                    vapidKey: 'BAlUeulOYhdHbGLNSzFn9R2OYuCjFeWv3C5GlV5oH_D8ejd4mQgkipB2fReDX5VrmhsF876gacsjcHgO-Z8llQk',
                                    serviceWorkerRegistration: registration
                                });
                            })
                            .then((currentToken) => {
                                if (currentToken) {
                                    subscribeToTopic(currentToken, 'all');
                                    subscribeToTopic(currentToken, 'driver');
                                }
                            })
                            .catch(function (err) {
                                console.error('❌ FCM Setup failed:', err);
                            });
                    }
                }
            });
        }

        function subscribeToTopic(token, topicName) {
            fetch('../subscribe_fcm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token, topic: topicName })
            })
                .then(res => { if (!res.ok) throw new Error('404'); return res.json(); })
                .catch(err => console.error(`❌ Sub failed for ${topicName}:`, err));
        }

        messaging.onMessage((payload) => {
            const audio = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
            audio.play();
            showDriverToast(payload.notification.title, payload.notification.body);
        });

        function showDriverToast(title, message) {
            const oldToast = document.getElementById('driver-alert-toast');
            if (oldToast) oldToast.remove();

            const toast = document.createElement('div');
            toast.id = 'driver-alert-toast';
            toast.style = `
                position: fixed; top: 20px; left: 20px; right: 20px;
                background: #333; color: white; padding: 15px;
                border-radius: 12px; z-index: 9999; display: flex;
                align-items: center; gap: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                border-left: 5px solid #dc3545; animation: slideDown 0.4s ease-out;
            `;
            toast.innerHTML = `
                <div style="background: #dc3545; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-bell"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 0.95rem;">${title}</div>
                    <div style="font-size: 0.8rem; opacity: 0.9;">${message}</div>
                </div>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; color:white; font-size:1.2rem;">&times;</button>
            `;
            document.body.appendChild(toast);
            setTimeout(() => { if (toast) toast.remove(); }, 8000);
        }

        requestNotificationPermission();
    }
});

// ==========================================
// 6. LIVE GPS BROADCASTER (HEARTBEAT)
// ==========================================
let gpsWatchId = null;
let lastGpsUpdateObj = 0;

function startGpsBroadcast() {
    if (!navigator.geolocation) {
        console.error("Geolocation not supported by this browser.");
        return;
    }

    gpsWatchId = navigator.geolocation.watchPosition(
        (position) => {
            const now = Date.now();
            // Throttle to update Firestore only once every 10 seconds
            if (now - lastGpsUpdateObj < 10000) return;

            if (typeof firebase !== 'undefined' && firebase.apps.length > 0 && typeof driverAssignedShuttle !== 'undefined' && driverAssignedShuttle) {
                const db = firebase.firestore();
                db.collection('Shuttles').doc(driverAssignedShuttle).set({
                    current_lat: position.coords.latitude,
                    current_lng: position.coords.longitude,
                    is_online: true,
                    last_updated: firebase.firestore.FieldValue.serverTimestamp()
                }, { merge: true }).then(() => {
                    lastGpsUpdateObj = Date.now();
                    console.log("GPS heartbeat sent.");
                }).catch(err => console.error("Error sending heartbeat:", err));
            }
        },
        (error) => {
            console.warn("GPS watch error:", error);
        },
        { enableHighAccuracy: true }
    );
}



document.addEventListener("DOMContentLoaded", function () {
    if (typeof driverIsOnline !== 'undefined' && driverIsOnline && typeof driverAssignedShuttle !== 'undefined' && driverAssignedShuttle) {
        startGpsBroadcast();
    }
});

