// ==========================================
// 1. STATUS TOGGLE
// ==========================================
function toggleStatus() {
    const dot = document.getElementById('statusDot');
    const text = document.getElementById('statusText');
    const isCurrentlyOnline = text.innerText.toUpperCase() === 'ONLINE';

    // Optimistically update UI
    const newStatus = isCurrentlyOnline ? 'OFFLINE' : 'ONLINE';
    text.innerText = newStatus;
    text.style.color = isCurrentlyOnline ? 'white' : '#2ecc71';

    dot.style.background = isCurrentlyOnline ? '#bdc3c7' : '#2ecc71';
    dot.style.boxShadow = isCurrentlyOnline ? 'none' : '0 0 8px #2ecc71';

    const pill = text.closest('.status-pill');
    if (pill) {
        pill.style.background = isCurrentlyOnline ? 'rgba(255,255,255,0.15)' : 'rgba(46, 204, 113, 0.15)';
        pill.style.border = isCurrentlyOnline ? '1px solid rgba(255,255,255,0.2)' : '1px solid rgba(46, 204, 113, 0.3)';
    }

    const offlineCard = document.getElementById('offline-card');
    const scanningCard = document.getElementById('scanning-card');
    if (offlineCard && scanningCard) {
        if (isCurrentlyOnline) { // Switching to Offline
            offlineCard.style.display = 'block';
            scanningCard.style.display = 'none';
            const pingContainer = document.getElementById('pinging-card-container');
            if (pingContainer) pingContainer.innerHTML = '';
        } else { // Switching to Online
            offlineCard.style.display = 'none';
            scanningCard.style.display = 'block';
        }
    }

    // FIX: Start/Stop GPS Tracking Immediately without needing a refresh!
    if (isCurrentlyOnline) {
        if (typeof stopGpsBroadcast === 'function') stopGpsBroadcast();
    } else {
        if (typeof startGpsBroadcast === 'function') startGpsBroadcast();
    }

    // Ping the backend to sync Driver AND Shuttle simultaneously
    fetch('toggle_status.php', { method: 'POST' }).then(() => {
        console.log("Duty Status Synced with Backend.");
    });
}

// ==========================================
// 2. DYNAMIC GEOLOCATION & BOTTOM SHEET
// ==========================================
function toggleReportSheet(e) {
    if (typeof isDragging !== 'undefined' && isDragging) return;
    if (e) e.preventDefault();

    const sheet = document.getElementById('reportSheet');
    const overlay = document.getElementById('reportOverlay');

    if (sheet.classList.contains('active')) {
        sheet.classList.remove('active');
        overlay.classList.remove('active');
        cancelReport(); // Reset the UI if they close the sheet early
    } else {
        sheet.classList.add('active');
        overlay.classList.add('active');

        // Reset UI to step 1
        document.getElementById('step1-grid').style.display = 'grid';
        document.getElementById('step2-confirm').style.display = 'none';
        document.getElementById('step3-success').style.display = 'none';
        document.getElementById('alert_details').value = '';

        const btn = document.getElementById('btnSubmitReport');
        if (btn) {
            btn.innerHTML = 'SEND ALERT <i class="fas fa-paper-plane"></i>';
            btn.disabled = false;
        }

        detectLocation();
    }
}

function detectLocation() {
    const locStatus = document.getElementById('locationStatusText');
    const locIcon = document.getElementById('locationIcon');
    const latInput = document.getElementById('driver_lat');
    const lngInput = document.getElementById('driver_lng');
    const nameInput = document.getElementById('detected_location');

    locStatus.innerHTML = 'Detecting coordinates... <i class="fas fa-circle-notch fa-spin"></i>';
    locStatus.style.color = '#f39c12';
    locIcon.innerHTML = '<i class="fas fa-circle-notch fa-spin" style="color: #3498db;"></i>';

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(position => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            latInput.value = lat;
            lngInput.value = lng;

            // Street Level Geocoding via OpenStreetMap
            locStatus.innerHTML = 'Finding street name... <i class="fas fa-circle-notch fa-spin"></i>';

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

                    locIcon.innerHTML = '<i class="fas fa-check-circle" style="color: #27ae60;"></i>';
                    locStatus.innerText = locationString;
                    locStatus.style.color = '#2d3748';
                    nameInput.value = locationString;
                })
                .catch(error => {
                    locIcon.innerHTML = '<i class="fas fa-check-circle" style="color: #27ae60;"></i>';
                    locStatus.innerText = `GPS: ${lat.toFixed(4)}, ${lng.toFixed(4)}`;
                    locStatus.style.color = '#2d3748';
                    nameInput.value = `GPS: ${lat.toFixed(4)}, ${lng.toFixed(4)}`;
                });

        }, error => {
            locStatus.innerHTML = 'Failed. Check GPS permissions.';
            locStatus.style.color = '#e74c3c';
            locIcon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i>';
        }, { enableHighAccuracy: true, timeout: 15000 });
    } else {
        locStatus.innerHTML = 'GPS not supported.';
        locStatus.style.color = '#e74c3c';
    }
}

// ==========================================
// 3. TWO-STEP REPORTING LOGIC & AJAX SUBMISSION
// ==========================================
let currentReportType = '';
let currentReportTitle = '';

const detailPlaceholders = {
    'breakdown': "e.g., Engine died, waiting for tow...",
    'accident': "e.g., Shuttle collided with car, need rescue...",
    'traffic': "e.g., Roadblock / Accident ahead blocking lanes...",
    'rain': "e.g., Flash flood near the library..."
};

function selectReportType(type, title, iconClass, color, bgColor) {
    currentReportType = type;
    currentReportTitle = title;

    document.getElementById('final_alert_type').value = type;
    document.getElementById('confirmTitle').innerText = title;

    const confirmIconBg = document.getElementById('confirmIconBg');
    const confirmIcon = document.getElementById('confirmIcon');

    confirmIcon.className = 'fas ' + iconClass;
    confirmIcon.style.color = color;
    confirmIconBg.style.backgroundColor = bgColor;

    document.getElementById('alert_details').placeholder = detailPlaceholders[type] || 'Provide additional details...';

    // Dynamic Helper Text Based on Category
    const helperText = document.getElementById('confirmHelperText');
    if (type === 'breakdown' || type === 'accident') {
        helperText.innerHTML = '<i class="fas fa-info-circle"></i> <b>WARNING:</b> This will flag your shuttle as disabled and automatically request an emergency replacement.';
        helperText.style.color = '#c53030';
        helperText.style.backgroundColor = '#fff5f5';
        helperText.style.border = '1px solid #feb2b2';
        helperText.style.display = 'block';
    } else if (type === 'traffic') {
        helperText.innerHTML = '<i class="fas fa-info-circle"></i> <b>Note:</b> Use this for traffic jams, roadblocks, or accidents ahead that DO NOT involve your shuttle.';
        helperText.style.color = '#c05621';
        helperText.style.backgroundColor = '#fffff0';
        helperText.style.border = '1px solid #fbd38d';
        helperText.style.display = 'block';
    } else {
        helperText.style.display = 'none';
    }

    document.getElementById('step1-grid').style.display = 'none';
    document.getElementById('step2-confirm').style.display = 'flex';
}

function cancelReport() {
    document.getElementById('step2-confirm').style.display = 'none';
    document.getElementById('step1-grid').style.display = 'grid';
    document.getElementById('final_alert_type').value = '';
    document.getElementById('alert_details').value = '';
}

// Attach event listener safely after DOM loads
document.addEventListener("DOMContentLoaded", function () {
    const liveReportForm = document.getElementById('liveReportForm');
    if (liveReportForm) {
        liveReportForm.addEventListener('submit', function (e) {
            e.preventDefault();

            // Intercept with SweetAlert2 if it's an emergency
            if (currentReportType === 'breakdown' || currentReportType === 'accident') {
                Swal.fire({
                    title: '🚨 Emergency Replacement 🚨',
                    html: "This will instantly flag your shuttle as disabled and dispatch a replacement vehicle to your location.<br><br><b>If you are just stuck in traffic caused by someone else's accident, please click Cancel and use 'Heavy Traffic' instead.</b>",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#4a5568',
                    confirmButtonText: 'Yes, Request Replacement',
                    cancelButtonText: 'Cancel',
                    customClass: { popup: 'swal-mobile-custom' }
                }).then((result) => {
                    if (result.isConfirmed) {
                        executeReportSubmission(this);
                    }
                });
            } else {
                executeReportSubmission(this);
            }
        });
    }
});

function executeReportSubmission(formElement) {
    const lat = document.getElementById('driver_lat').value;
    if (!lat) {
        Swal.fire('Location Missing', 'Please wait for your location to be detected before sending.', 'error');
        return;
    }

    const btn = document.getElementById('btnSubmitReport');
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> SENDING...';
    btn.disabled = true;

    const formData = new FormData(formElement);

    fetch(formElement.action, {
        method: formElement.method,
        body: formData
    })
        .then(response => {
            if (response.ok) {
                document.getElementById('step2-confirm').style.display = 'none';
                document.getElementById('step3-success').style.display = 'flex';

                setTimeout(() => {
                    toggleReportSheet();

                    setTimeout(() => {
                        document.getElementById('step3-success').style.display = 'none';
                        document.getElementById('step1-grid').style.display = 'grid';
                        btn.innerHTML = 'SEND ALERT <i class="fas fa-paper-plane"></i>';
                        btn.disabled = false;
                        document.getElementById('alert_details').value = '';
                    }, 400);

                }, 2500);
            } else {
                throw new Error("Server error");
            }
        })
        .catch(error => {
            console.error("Error sending report: ", error);
            Swal.fire('Error', 'Failed to send report. Please check your connection.', 'error');
            btn.innerHTML = 'SEND ALERT <i class="fas fa-paper-plane"></i>';
            btn.disabled = false;
        });
}


// ==========================================
// 4. DRAGGABLE FLOATING BUTTON
// ==========================================
const fab = document.getElementById('draggableFab');
let isFabDragging = false;
let fabStartY, fabStartTop;

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

    // Prevent multiple overlapping trackers
    if (gpsWatchId !== null) return;

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
                    console.log("GPS heartbeat sent. Coords:", position.coords.latitude, position.coords.longitude);
                }).catch(err => console.error("Error sending heartbeat:", err));
            }
        },
        (error) => {
            console.warn("GPS watch error:", error.message);
        },
        // Added stricter accuracy requirements for mobile devices
        { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 } 
    );
}

// NEW: Function to kill the GPS tracker when driver goes offline
function stopGpsBroadcast() {
    if (gpsWatchId !== null && navigator.geolocation) {
        navigator.geolocation.clearWatch(gpsWatchId);
        gpsWatchId = null;
        console.log("GPS heartbeat stopped.");
    }
}

document.addEventListener("DOMContentLoaded", function () {
    if (typeof driverIsOnline !== 'undefined' && driverIsOnline && typeof driverAssignedShuttle !== 'undefined' && driverAssignedShuttle) {
        startGpsBroadcast();
    }
});