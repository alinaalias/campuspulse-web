import time
import googlemaps # REQUIRED: pip install googlemaps
from google.cloud import firestore
from google.oauth2 import service_account

# 1. SETUP CREDENTIALS
key_path = r"C:\xampp\htdocs\FYP\service-account.json"
creds = service_account.Credentials.from_service_account_file(key_path)
db = firestore.Client(credentials=creds)

# 2. GOOGLE MAPS CONFIGURATION
# Paste your API Key here
GMAPS_API_KEY = "AIzaSyAhJXLX5-6MTNRXHoutsSrZWI99BJCjeo4" 
gmaps = googlemaps.Client(key=GMAPS_API_KEY)

UPDATE_INTERVAL = 1  # Update database every 3 seconds
SPEED_MULTIPLIER = 1 # How many road points to jump per interval (higher = faster van)

def generate_patrol_routes():
    print("\n--- 🗺️ MAPPING REAL ROAD ROUTES FOR ZONES ---")
    zone_stops = {}
    zone_routes = {}

    # 1. Gather all stops for each zone
    stops = db.collection("Stops").stream()
    for s in stops:
        d = s.to_dict()
        lat, lng = d.get('lat'), d.get('lng')
        zids = d.get('zone_ids') or ([d.get('zone_id')] if d.get('zone_id') else [])
        
        if lat and lng:
            for zid in zids:
                if zid not in zone_stops: zone_stops[zid] = []
                zone_stops[zid].append((float(lat), float(lng)))

    # 2. Ask Google Maps for a driving route connecting the stops
    for zid, coords in zone_stops.items():
        if len(coords) < 2:
            print(f"⚠️ Zone '{zid}' needs at least 2 stops to make a road route. Skipping.")
            continue
            
        print(f"📍 Calculating road path for Zone '{zid}'...")
        
        origin = coords[0]
        destination = coords[0] # Loop back to the start
        waypoints = coords[1:]  # Visit all other stops in between

        try:
            directions = gmaps.directions(
                origin,
                destination,
                waypoints=waypoints,
                mode="driving"
            )
            
            if directions:
                # Extract the encoded string of the entire route's curves and turns
                poly_string = directions[0]['overview_polyline']['points']
                # Decode it into a precise list of {lat, lng} dictionaries
                route_points = googlemaps.convert.decode_polyline(poly_string)
                zone_routes[zid] = route_points
                print(f"   ✅ Success: Route mapped with {len(route_points)} precise road points.")
            else:
                print(f"   ❌ Failed: Google could not find a road route.")
                
        except Exception as e:
            print(f"   ❌ API Error for {zid}: {e}")

    return zone_routes

try:
    # Calculate routes ONCE at startup to save Google Maps API costs
    patrol_routes = generate_patrol_routes()
    print("\n🚀 CampusPulse Simulator Active (Road-Snap Mode).")
    print("Press Ctrl+C to stop.\n")

    # Dictionary to track where each shuttle is on its route array
    shuttle_progress = {}

    while True:
        shuttles = db.collection("Shuttles").where("status", "==", "active").stream()
        
        for s in shuttles:
            sid = s.id
            data = s.to_dict()
            zid = data.get("zone_id")

            # Get the pre-calculated road route for this shuttle's zone
            route = patrol_routes.get(zid)
            
            if not route:
                continue # Skip if this zone doesn't have a valid road route
            
            # If shuttle is new to the simulation, start at point 0
            if sid not in shuttle_progress:
                shuttle_progress[sid] = 0
            else:
                # Move forward along the road
                shuttle_progress[sid] += SPEED_MULTIPLIER 

            # If it reaches the end of the route, loop back to the beginning
            if shuttle_progress[sid] >= len(route):
                shuttle_progress[sid] = 0

            # Get exact road coordinate
            current_location = route[shuttle_progress[sid]]

            # Update Firestore
            db.collection("Shuttles").document(sid).update({
                "current_lat": current_location['lat'], 
                "current_lng": current_location['lng'],
                "is_online": True, 
                "job_status": "Patrolling", 
                "last_updated": firestore.SERVER_TIMESTAMP
            })
            
            print(f"🚐 {sid} driving on road... (Point {shuttle_progress[sid]}/{len(route)})")
            
        time.sleep(UPDATE_INTERVAL)

except KeyboardInterrupt:
    print("\n🛑 Simulation Stopped.")
except Exception as e:
    print(f"\n💥 Crash: {e}")