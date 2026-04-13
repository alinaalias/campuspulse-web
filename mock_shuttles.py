import time
import math
import random
import googlemaps # REQUIRED: pip install googlemaps
from google.cloud import firestore
from google.oauth2 import service_account

# 1. SETUP CREDENTIALS
key_path = r"C:\xampp\htdocs\FYP\service-account.json"
creds = service_account.Credentials.from_service_account_file(key_path)
db = firestore.Client(credentials=creds)

# 2. GOOGLE MAPS CONFIGURATION
# Replace with your actual API Key from config.php
GMAPS_API_KEY = "AIzaSyAhJXLX5-6MTNRXHoutsSrZWI99BJCjeo4" 
gmaps = googlemaps.Client(key=GMAPS_API_KEY)

# --- CONFIGURATION ---
ZONE_SCATTER_RADIUS = 0.005
UPDATE_INTERVAL = 5
FALLBACK_CENTER = {"lat": 3.1592, "lng": 101.7036}
EXCLUDED_SHUTTLES = [] 

# Dictionary to store the road paths for shuttles currently on a job
# Format: { "CPS003": [ (lat1, lng1), (lat2, lng2), ... ] }
route_paths = {}

def get_calculated_zone_centers():
    zone_coords = {}
    final_centers = {}
    print("\n--- 🔍 ANALYZING STOPS TO FIND ZONE CENTERS ---")
    stops = db.collection("Stops").stream()
    for s in stops:
        data = s.to_dict()
        lat, lng = data.get('lat'), data.get('lng')
        zids = data.get('zone_ids') or ([data.get('zone_id')] if data.get('zone_id') else [])
        if lat and lng and zids:
            for zid in zids:
                if zid not in zone_coords: zone_coords[zid] = {'lats': [], 'lngs': []}
                zone_coords[zid]['lats'].append(float(lat))
                zone_coords[zid]['lngs'].append(float(lng))
    for zid, coords in zone_coords.items():
        final_centers[zid] = {"lat": sum(coords['lats']) / len(coords['lats']), "lng": sum(coords['lngs']) / len(coords['lngs'])}
        print(f"📍 {zid}: Center set.")
    return final_centers

global_stops = {}
def get_stop_coords(stop_id):
    if not stop_id: return None
    if stop_id in global_stops: return global_stops[stop_id]
    s = db.collection("Stops").document(stop_id).get()
    if s.exists:
        d = s.to_dict()
        coords = {"lat": float(d.get("lat", d.get("latitude"))), "lng": float(d.get("lng", d.get("longitude")))}
        global_stops[stop_id] = coords
        return coords
    return None

def fetch_road_route(start_lat, start_lng, end_lat, end_lng):
    """Calls Google Directions API to get a list of coordinates along the road."""
    try:
        directions = gmaps.directions(
            (start_lat, start_lng),
            (end_lat, end_lng),
            mode="driving"
        )
        if not directions: return []
        
        points = []
        # Extract every step's coordinate to build a high-detail path
        for step in directions[0]['legs'][0]['steps']:
            points.append((step['start_location']['lat'], step['start_location']['lng']))
        points.append((directions[0]['legs'][0]['end_location']['lat'], directions[0]['legs'][0]['end_location']['lng']))
        return points
    except Exception as e:
        print(f"❌ Google Maps API Error: {e}")
        return []

def process_ondemand_trips():
    busy_shuttles = set()
    
    # 1. AUTO-ACCEPT LOGIC: If a student requests ('pending'), force CPS003 to accept it
    pending = db.collection("Bookings").where("type", "==", "ondemand").where("status", "==", "pending").stream()
    for p in pending:
        print(f"🔔 Student Request Found! Assigning CPS003...")
        p.reference.update({
            "status": "confirmed",
            "shuttle_id": "CPS003",
            "driver_id": "DRV001", # Ensure this ID exists in your Staffs
            "updated_at": firestore.SERVER_TIMESTAMP
        })

    # 2. MOVEMENT LOGIC: Handle confirmed (heading to pickup) and onboard (heading to dropoff)
    bookings = db.collection("Bookings").where("type", "==", "ondemand").where("status", "in", ["confirmed", "arriving", "onboard"]).stream()
    
    for b in bookings:
        b_id, b_data = b.id, b.to_dict()
        s_id, status = b_data.get("shuttle_id"), b_data.get("status")
        if not s_id: continue
        busy_shuttles.add(s_id)
        
        s_doc = db.collection("Shuttles").document(s_id).get().to_dict()
        c_lat, c_lng = s_doc.get("current_lat"), s_doc.get("current_lng")

        # Determine destination based on status
        target_stop_id = b_data.get("pickup_stop_id") if status == "confirmed" else b_data.get("dropoff_stop_id")
        target_coords = get_stop_coords(target_stop_id)

        # Handle 'arriving' status (The Boarding Pause)
        if status == "arriving":
            print(f"[{s_id}] ⏳ Boarding passenger at {target_stop_id}...")
            # Automatically move to 'onboard' after one cycle
            db.collection("Bookings").document(b_id).update({"status": "onboard", "updated_at": firestore.SERVER_TIMESTAMP})
            if s_id in route_paths: del route_paths[s_id] # Clear path to recalculate for next leg
            continue

        # FETCH ROAD PATH if not already in memory
        if s_id not in route_paths and target_coords:
            print(f"🗺️  Calculating ROAD ROUTE for {s_id} toward {target_stop_id}...")
            route_paths[s_id] = fetch_road_route(c_lat, c_lng, target_coords['lat'], target_coords['lng'])

        # MOVE THE SHUTTLE along the road path
        if s_id in route_paths and len(route_paths[s_id]) > 0:
            next_point = route_paths[s_id].pop(0) # Pop the next road coordinate
            
            db.collection("Shuttles").document(s_id).update({
                "current_lat": next_point[0],
                "current_lng": next_point[1],
                "job_status": "In Job",
                "last_updated": firestore.SERVER_TIMESTAMP
            })
            print(f"[{s_id}] 🛣️  Following Road... {len(route_paths[s_id])} points remaining.")

            # Check if we just popped the last point (Arrived)
            if len(route_paths[s_id]) == 0:
                if status == "confirmed":
                    print(f"[{s_id}] 🏁 Reached Pickup!")
                    db.collection("Bookings").document(b_id).update({"status": "arriving"})
                elif status == "onboard":
                    print(f"[{s_id}] ✅ Trip Complete!")
                    db.collection("Bookings").document(b_id).update({"status": "completed"})
                    db.collection("Shuttles").document(s_id).update({"job_status": "Idle"})
                    del route_paths[s_id]
                    
    return busy_shuttles

try:
    zone_map = get_calculated_zone_centers()
    print("🚀 CampusPulse Simulator Active.")

    while True:
        busy_shuttles = process_ondemand_trips()
        shuttles = db.collection("Shuttles").where("status", "==", "active").stream()
        
        for s in shuttles:
            s_id = s.id
            if s_id in EXCLUDED_SHUTTLES or s_id in busy_shuttles: continue
            
            data = s.to_dict()
            zone_id = data.get("zone_id")
            center = zone_map.get(zone_id, FALLBACK_CENTER)

            # Scatter Logic for Idle Shuttles
            random.seed(s_id) 
            base_r = ZONE_SCATTER_RADIUS * math.sqrt(random.random())
            base_theta = random.random() * 2 * math.pi
            jitter_speed = time.time() * 0.1
            new_lat = center["lat"] + (base_r * math.cos(base_theta)) + (math.cos(jitter_speed) * 0.00015)
            new_lng = center["lng"] + (base_r * math.sin(base_theta)) + (math.sin(jitter_speed) * 0.00015)
            
            db.collection("Shuttles").document(s_id).update({
                "current_lat": new_lat, "current_lng": new_lng,
                "is_online": True, "job_status": "Idle", "last_updated": firestore.SERVER_TIMESTAMP
            })
            
        random.seed()
        time.sleep(UPDATE_INTERVAL)

except KeyboardInterrupt:
    print("\n🛑 Simulation Stopped.")
except Exception as e:
    print(f"\n💥 Crash: {e}")