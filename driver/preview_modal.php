<!-- PREVIEW MODAL -->
<div id="previewModal" onclick="if(event.target === this) closePreview()">
    <div class="modal-content" id="previewModalContent">
        <div style="width:40px; height:5px; background:#e2e8f0; border-radius:5px; margin:0 auto 20px;"></div>

        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
            <div>
                <div style="font-size:0.8rem; color:#718096; font-weight:700; text-transform:uppercase;">Trip
                    Preview</div>
                <h3 id="previewRouteName" style="margin:5px 0 0; font-size:1.4rem; color:#2d3748;">Loading...</h3>
            </div>
            <button onclick="closePreview()"
                style="background:#f1f3f5; border:none; width:34px; height:34px; border-radius:50%; font-size:1.2rem; color:#4a5568; cursor:pointer;">
                &times;</button>
        </div>

        <div id="previewStats" style="display:flex; gap:10px; margin-bottom:20px;"></div>

        <div style="flex:1; overflow-y:auto; margin-bottom:20px; padding: 5px 10px;">
            <div id="previewTimeline" style="position:relative;">
                <div style="text-align:center; padding:30px 0; color:#a0aec0;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                </div>
            </div>
        </div>

        <a href="#" id="previewStartBtn"
            style="background:#27ae60; color:white; width:100%; padding:18px; border-radius:16px; font-size:1.15rem; font-weight:700; text-align:center; text-decoration:none; display:flex; justify-content:center; align-items:center; gap:10px; box-shadow: 0 4px 15px rgba(39, 174, 96, 0.2);">
            START TRIP <i class="fas fa-play"></i>
        </a>
    </div>
</div>

<script>
    function previewTrip(tripId, routeName, timeStr, tripDate, todayDate, isOngoing) {
        document.getElementById('previewRouteName').innerText = routeName + " (" + timeStr + ")";
        
        let startBtn = document.getElementById('previewStartBtn');
        startBtn.href = 'active_trip.php?id=' + encodeURIComponent(tripId);
        
        if (isOngoing) {
            startBtn.innerHTML = 'RESUME TRIP <i class="fas fa-play"></i>';
            startBtn.style.background = '#27ae60';
            startBtn.style.pointerEvents = 'auto';
        } else if (tripDate > todayDate) {
            startBtn.innerHTML = 'AVAILABLE ON ' + tripDate;
            startBtn.style.background = '#a0aec0';
            startBtn.style.pointerEvents = 'none';
        } else {
            startBtn.innerHTML = 'START TRIP <i class="fas fa-play"></i>';
            startBtn.style.background = '#27ae60';
            startBtn.style.pointerEvents = 'auto';
        }

        document.getElementById('previewTimeline').innerHTML = '<div style="text-align:center; padding:30px 0; color:#a0aec0;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        document.getElementById('previewStats').innerHTML = '';
        document.getElementById('previewModal').style.display = 'flex';
        document.body.classList.add('modal-open');

        let cleanId = tripId.startsWith('SCHED:') ? tripId.substring(6) : tripId;

        fetch('fetch_schedule_details.php?id=' + encodeURIComponent(cleanId))
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('previewStats').innerHTML = `
                        <div style="flex:1; background:#f8f9fa; padding:12px; border-radius:12px; text-align:center;">
                            <div style="font-size:1.3rem; font-weight:700; color:#3498db;">${data.stops.length}</div>
                            <div style="font-size:0.7rem; font-weight:700; color:#a0aec0; text-transform:uppercase;">Stops</div>
                        </div>
                        <div style="flex:1; background:#f8f9fa; padding:12px; border-radius:12px; text-align:center;">
                            <div style="font-size:1.3rem; font-weight:700; color:#f39c12;">${data.booked_count}</div>
                            <div style="font-size:0.7rem; font-weight:700; color:#a0aec0; text-transform:uppercase;">Booked</div>
                        </div>
                        <div style="flex:1; background:#f8f9fa; padding:12px; border-radius:12px; text-align:center;">
                            <div style="font-size:1.3rem; font-weight:700; color:#2ecc71;">${Math.max(0, data.capacity - data.booked_count)}</div>
                            <div style="font-size:0.7rem; font-weight:700; color:#a0aec0; text-transform:uppercase;">Seats Left</div>
                        </div>
                    `;

                    let timelineHTML = `
                        <div style="position: absolute; left: 7px; top: 10px; bottom: 20px; width: 2px; background: #e2e8f0; z-index: 1;"></div>
                    `;
                    data.stops.forEach((stop, idx) => {
                        let dotColor = idx === 0 ? '#2ecc71' : (idx === data.stops.length - 1 ? '#e74c3c' : '#3498db');
                        timelineHTML += `
                            <div style="position:relative; margin-bottom:25px; padding-left:35px; z-index: 2;">
                                <div style="position:absolute; left:0; top:2px; width:16px; height:16px; border-radius:50%; background:white; border:3px solid ${dotColor}; box-sizing:border-box;"></div>
                                
                                <div style="font-weight:700; color:#2d3748; font-size:1.05rem;">${stop.name}</div>
                                <div style="font-size:0.85rem; color:#718096; font-weight:600;">${stop.time ? stop.time : 'Scheduled Stop'}</div>
                            </div>
                        `;
                    });
                    document.getElementById('previewTimeline').innerHTML = timelineHTML;
                } else {
                    document.getElementById('previewTimeline').innerHTML = `<div style="text-align:center; padding:20px; color:#e53e3e; font-weight:600;">${data.message}</div>`;
                }
            })
            .catch(() => {
                document.getElementById('previewTimeline').innerHTML = `<div style="text-align:center; padding:20px; color:#e53e3e; font-weight:600;">Could not load details.</div>`;
            });
    }

    // --- CLOSE MODAL WITH ANIMATION ---
    function closePreview() {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewModalContent');

        // Add the slide-down animation class
        content.classList.add('slide-down-anim');
        document.body.classList.remove('modal-open');

        // Wait for the animation to finish (250ms), then hide and clean up
        setTimeout(() => {
            modal.style.display = 'none';
            content.classList.remove('slide-down-anim');
            content.style.transform = ''; // Reset any drag transforms
        }, 250);
    }

    // --- SWIPE DOWN TO CLOSE LOGIC ---
    const previewModalContent = document.getElementById('previewModalContent');
    let startY = 0;
    let currentY = 0;
    let isDraggingModal = false;

    // Check if element exists to avoid errors on pages where modal is included but logic wasn't initialized cleanly
    if (previewModalContent) {
        previewModalContent.addEventListener('touchstart', (e) => {
            // Only allow dragging if they touch the top area (the drag handle or header)
            // Prevent dragging if they are scrolling the timeline
            if (e.target.closest('#previewTimeline') || e.target.closest('#previewStats')) return;

            startY = e.touches[0].clientY;
            isDraggingModal = true;
            previewModalContent.style.transition = 'none'; // Remove CSS transitions while dragging
        }, { passive: true });

        previewModalContent.addEventListener('touchmove', (e) => {
            if (!isDraggingModal) return;

            currentY = e.touches[0].clientY;
            let deltaY = currentY - startY;

            // Only allow dragging DOWNwards
            if (deltaY > 0) {
                previewModalContent.style.transform = `translateY(${deltaY}px)`;
            }
        }, { passive: true });

        previewModalContent.addEventListener('touchend', (e) => {
            if (!isDraggingModal) return;
            isDraggingModal = false;

            let deltaY = currentY - startY;
            previewModalContent.style.transition = 'transform 0.25s ease-out';

            // If they swiped down more than 100 pixels, close the modal
            if (deltaY > 100) {
                closePreview();
            } else {
                // Otherwise, snap it back to the top
                previewModalContent.style.transform = 'translateY(0)';
            }

            // Clean up transition inline styles after snapping back
            setTimeout(() => {
                if (previewModalContent.style.transform === 'translateY(0px)') {
                    previewModalContent.style.transition = '';
                    previewModalContent.style.transform = '';
                }
            }, 250);
        });
    }
</script>
