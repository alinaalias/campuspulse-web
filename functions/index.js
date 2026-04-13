const functions = require("firebase-functions");
const admin = require("firebase-admin");
admin.initializeApp();

exports.matchmaker = functions.firestore
    .document("Bookings/{bookingId}")
    .onCreate(async (snap, context) => {
        const booking = snap.data();
        
        // Initial Filter
        if (booking.type !== "ondemand" || booking.status !== "pending") {
            return null;
        }

        const db = admin.firestore();
        const bookingRef = snap.ref;
        
        console.log(`[Matchmaker] Processing new on-demand booking: ${context.params.bookingId}`);
        
        try {
            // Step 1: Hotspot Efficiency Check (Batching)
            const batchCandidatesQuery = await db.collection("Bookings")
                .where("type", "==", "ondemand")
                .where("status", "in", ["confirmed", "arriving", "onboard"])
                .where("dropoff_stop_id", "==", booking.dropoff_stop_id)
                .get();
                
            let batched = false;
            for (const doc of batchCandidatesQuery.docs) {
                const bData = doc.data();
                if (!bData.shuttle_id) continue;
                
                const sDoc = await db.collection("Shuttles").doc(bData.shuttle_id).get();
                if (sDoc.exists) {
                    const sData = sDoc.data();
                    if (sData.zone_id === booking.zone_id) {
                        // Assuming capacity holds, suggest this batch!
                        await bookingRef.update({
                            status: "suggested_batch",
                            candidate_shuttle_id: bData.shuttle_id,
                            candidate_driver_id: bData.driver_id || null,
                            updated_at: admin.firestore.FieldValue.serverTimestamp()
                        });
                        console.log(`[Matchmaker] Batched efficiently with active Shuttle ${bData.shuttle_id}`);
                        batched = true;
                        break;
                    }
                }
            }
            
            if (batched) return null;
            
            // Step 2: Proximity Dispatch (Finding nearest Idle Asset)
            const pickupStopRef = await db.collection("Stops").doc(booking.pickup_stop_id).get();
            if (!pickupStopRef.exists) {
                console.error("[Matchmaker] Pickup Stop not found");
                return null;
            }
            
            const pData = pickupStopRef.data();
            const pickupLat = parseFloat(pData.latitude || pData.lat);
            const pickupLng = parseFloat(pData.longitude || pData.lng);
            
            const shuttlesQuery = await db.collection("Shuttles")
                .where("zone_id", "==", booking.zone_id)
                .where("job_status", "==", "Idle")
                .where("is_online", "==", true)
                .where("status", "==", "active")
                .get();
                
            if (shuttlesQuery.empty) {
                console.log("[Matchmaker] No Idle online shuttles available in Zone: " + booking.zone_id);
                return null;
            }
            
            let nearestShuttleId = null;
            let minDistance = Infinity;
            
            shuttlesQuery.forEach(doc => {
                const s = doc.data();
                const dLat = parseFloat(s.current_lat || 0);
                const dLng = parseFloat(s.current_lng || 0);
                
                // Euclidean Calculation Formula
                const d = Math.sqrt(Math.pow(dLat - pickupLat, 2) + Math.pow(dLng - pickupLng, 2));
                if (d < minDistance) {
                    minDistance = d;
                    nearestShuttleId = doc.id;
                }
            });
            
            if (!nearestShuttleId) return null;
            
            // Look up precise Driver ID from Staffs mapping
            const staffQuery = await db.collection("Staffs")
                .where("assigned_shuttle_id", "==", nearestShuttleId)
                .where("role", "==", "driver")
                .limit(1)
                .get();
                
            let candidateDriverId = nearestShuttleId; 
            if (!staffQuery.empty) {
                candidateDriverId = staffQuery.docs[0].id;
            }
            
            // Step 3: State Transition
            await bookingRef.update({
                status: "searching",
                candidate_driver_id: candidateDriverId,
                candidate_shuttle_id: nearestShuttleId,
                updated_at: admin.firestore.FieldValue.serverTimestamp()
            });
            
            console.log(`[Matchmaker] Dispatched -> Nearest Shuttle ${nearestShuttleId} (Driver: ${candidateDriverId})`);
            return null;
            
        } catch (error) {
            console.error("[Matchmaker] Execution Error:", error);
            return null;
        }
    });
