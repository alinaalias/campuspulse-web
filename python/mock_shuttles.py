import time
import math
import os 
import threading
import tkinter as tk
from tkinter import scrolledtext, messagebox
from datetime import datetime
import googlemaps
from google.cloud import firestore
from google.cloud.firestore_v1.base_query import FieldFilter
from google.oauth2 import service_account
from dotenv import load_dotenv 

# ==========================================
# 1. SETUP & CONFIGURATION
# ==========================================
load_dotenv()

key_path = r"C:\xampp\htdocs\FYP\service-account.json"
creds = service_account.Credentials.from_service_account_file(key_path)
db = firestore.Client(credentials=creds)

GMAPS_API_KEY = os.getenv("GOOGLE_MAPS_API_KEY")

if not GMAPS_API_KEY:
    print("🚨 ERROR: GOOGLE_MAPS_API_KEY not found in .env file!")
    exit()

gmaps = googlemaps.Client(key=GMAPS_API_KEY)

UPDATE_INTERVAL = 3  
SPEED_MULTIPLIER = 2 
SIMULATED_SPEED_KMH = 40 

cached_zone_routes = {}
cached_custom_routes = {} 
cached_stops = {}

# Simulator Control Flags
SIMULATOR_RUNNING = False
SELECTED_SHUTTLES = set() 

# ==========================================
# 2. HELPER FUNCTIONS
# ==========================================
def haversine_distance(lat1, lon1, lat2, lon2):
    R = 6371
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat/2) * math.sin(dlat/2) + math.cos(math.radians(lat1)) \
        * math.cos(math.radians(lat2)) * math.sin(dlon/2) * math.sin(dlon/2)
    c = 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))
    return R * c

def format_eta(minutes):
    m = int(minutes)
    if m <= 0: return "Arriving"
    if m < 60: return f"{m} mins"
    h = m // 60
    rem = m % 60
    return f"{h} hr {rem} mins"

def fetch_all_stops():
    global cached_stops
    stops = db.collection("Stops").stream()
    for s in stops:
        d = s.to_dict()
        if 'lat' in d and 'lng' in d:
            cached_stops[s.id] = (d['lat'], d['lng'])

def build_route_polyline(origin, destination, waypoints=None):
    cache_key = f"{origin}_{destination}_{len(waypoints or [])}"
    if cache_key in cached_custom_routes:
        return cached_custom_routes[cache_key]

    try:
        # Enforce strict route sequence and process high-resolution step polylines
        directions = gmaps.directions(
            origin, 
            destination, 
            waypoints=waypoints, 
            mode="driving", 
            optimize_waypoints=False # Prevent Google from scrambling scheduled stops
        )
        
        if directions:
            route_points = []
            
            # Use high-resolution step polylines instead of the smoothed overview_polyline
            for leg in directions[0]['legs']:
                for step in leg['steps']:
                    poly_string = step['polyline']['points']
                    route_points.extend(googlemaps.convert.decode_polyline(poly_string))
                
                # Explicitly inject the exact end coordinate of each leg (the intermediate stops)
                # This guarantees the shuttle physically touches the exact location of the stop.
                route_points.append({'lat': leg['end_location']['lat'], 'lng': leg['end_location']['lng']})
            
            cached_custom_routes[cache_key] = route_points
            return route_points
    except Exception as e:
        print(f"Maps API Error: {e}")
    return []

def fetch_available_shuttles():
    shuttles = db.collection("Shuttles").stream()
    return sorted([s.id for s in shuttles])

# ==========================================
# 3. THE SHUTTLE STATE MACHINE
# ==========================================
class ShuttleState:
    def __init__(self, shuttle_id):
        self.shuttle_id = shuttle_id
        self.mode = "INIT" 
        self.current_route = []
        self.progress_index = 0
        self.target_task_id = None 
        self.last_known_status = ""
        self.eta_text = ""
        
        self.wait_ticks = 0
        self.is_parked = False
        self.schedule_stops = []
        self.next_stop_idx = 0

    def update(self, sData):
        if not sData.get("is_online", False) or sData.get("status", "").lower() == "maintenance":
            return

        today = datetime.now().strftime('%Y-%m-%d')
        
        active_sched = self.find_active_schedule(today)
        if active_sched:
            self.execute_schedule_logic(active_sched)
            return

        active_od = self.find_active_ondemand()
        if active_od:
            self.execute_ondemand_logic(active_od)
            return

        self.execute_roaming_logic(sData.get("zone_id"))

    def find_active_schedule(self, today):
        scheds = db.collection('Schedules')\
            .where(filter=FieldFilter('shuttle_id', '==', self.shuttle_id))\
            .where(filter=FieldFilter('date', '==', today)).stream()
        
        current_time = datetime.now().strftime('%H:%M')
        for s in scheds:
            d = s.to_dict()
            status = d.get('status', '')
            
            if status == 'active': return s
            if status in ['published', 'scheduled']:
                if d.get('departure_time', '23:59') <= current_time:
                    log_to_ui(f"[{self.shuttle_id}] ⏱️ Auto-starting scheduled trip {s.id}")
                    db.collection('Schedules').document(s.id).update({'status': 'active'})
                    return s
        return None

    def find_active_ondemand(self):
        books = db.collection('Bookings')\
            .where(filter=FieldFilter('shuttle_id', '==', self.shuttle_id))\
            .where(filter=FieldFilter('type', '==', 'ondemand'))\
            .where(filter=FieldFilter('status', 'in', ['confirmed', 'arriving', 'arrived', 'onboard'])).limit(1).stream()
        for b in books: return b
        return None

    def execute_schedule_logic(self, sched_doc):
        sch_data = sched_doc.to_dict()
        sch_id = sched_doc.id
        db_stop_index = sch_data.get('current_stop_index', 0)

        if self.mode != "SCHEDULE" or self.target_task_id != sch_id:
            log_to_ui(f"\n[{self.shuttle_id}] 🔀 Switching to SCHEDULE mode ({sch_id})")
            self.mode = "SCHEDULE"
            self.target_task_id = sch_id
            self.progress_index = 0
            self.wait_ticks = 0
            self.is_parked = False
            self.next_stop_idx = db_stop_index
            self.schedule_stops = []
            
            route_id = sch_data.get('route_id')
            rSnap = db.collection('Routes').document(route_id).get()
            if rSnap.exists:
                stops = rSnap.to_dict().get('stop_ids', [])
                coords = []
                for st in stops:
                    stop_id = st.get('stop_id') if isinstance(st, dict) else st
                    self.schedule_stops.append(stop_id) 
                    if stop_id in cached_stops:
                        coords.append(cached_stops[stop_id])
                
                if len(coords) >= 2:
                    self.current_route = build_route_polyline(coords[0], coords[-1], waypoints=coords[1:-1])
                else:
                    log_to_ui(f"[{self.shuttle_id}] ❌ Schedule Error: Not enough valid stops to generate route.")

        # Sync the simulation with the Driver's actual UI progress
        if db_stop_index > self.next_stop_idx:
            log_to_ui(f"🚐 {self.shuttle_id} | ✅ Driver cleared stop. Proceeding to next destination.\n")
            self.next_stop_idx = db_stop_index
            self.is_parked = False
            self.wait_ticks = 0

        self.drive_and_update(sch_id, is_schedule=True, db_stop_index=db_stop_index)

    def execute_ondemand_logic(self, book_doc):
        b_data = book_doc.to_dict()
        b_id = book_doc.id
        status = b_data.get('status')

        if self.mode != "ONDEMAND" or self.target_task_id != b_id or self.last_known_status != status:
            log_to_ui(f"\n[{self.shuttle_id}] 🔀 Switching to ONDEMAND mode ({status})")
            self.mode = "ONDEMAND"
            self.target_task_id = b_id
            self.last_known_status = status
            self.progress_index = 0
            self.wait_ticks = 0 
            self.is_parked = False

            sLat, sLng = b_data.get('pickup_lat'), b_data.get('pickup_lng')
            dLat, dLng = None, None
            
            if b_data.get('dropoff_stop_id') in cached_stops:
                dLat, dLng = cached_stops[b_data.get('dropoff_stop_id')]

            if status in ['confirmed', 'arriving', 'arrived'] and sLat and sLng:
                current_loc = self.get_current_db_loc()
                if current_loc:
                    self.current_route = build_route_polyline(current_loc, (sLat, sLng))
            elif status == 'onboard' and dLat and dLng:
                self.current_route = build_route_polyline((sLat, sLng), (dLat, dLng))

        self.drive_and_update(b_id, is_schedule=False, od_status=status)

    def execute_roaming_logic(self, zone_id):
        if not zone_id or zone_id not in cached_zone_routes: 
            return

        if self.mode != "ROAMING":
            log_to_ui(f"[{self.shuttle_id}] ♻️ Reverting to ROAMING in zone {zone_id}")
            self.mode = "ROAMING"
            self.target_task_id = None
            self.current_route = cached_zone_routes[zone_id]
            
            # THE FIX: Stagger roaming start positions to prevent overlap
            route_len = len(self.current_route)
            if route_len > 0:
                # Deterministic offset using ASCII values of the shuttle ID
                shuttle_hash = sum(ord(c) for c in self.shuttle_id)
                self.progress_index = shuttle_hash % route_len
            else:
                self.progress_index = 0
                
            self.wait_ticks = 0 
            self.is_parked = False

        self.drive_and_update()

    def process_boarding(self, schedule_id, stop_id):
        # Only log every 4 ticks so we don't spam the UI while waiting
        if not getattr(self, 'is_parked', False) or self.wait_ticks % 4 == 0:
            log_to_ui(f"🚐 {self.shuttle_id} | 🛑 Parked at {stop_id}. Waiting for driver to click 'DEPART STOP'...")
            
            books = db.collection('Bookings')\
                .where(filter=FieldFilter('schedule_id', '==', schedule_id))\
                .where(filter=FieldFilter('pickup_stop_id', '==', stop_id))\
                .where(filter=FieldFilter('status', '==', 'confirmed')).stream()
            
            waiting_count = sum(1 for _ in books)
            if waiting_count > 0:
                log_to_ui(f"   📱 {waiting_count} student(s) waiting. Awaiting manual QR scan...")
                
        self.is_parked = True
        self.wait_ticks += 1

    def drive_and_update(self, task_id=None, is_schedule=False, db_stop_index=0, od_status=""):
        if not self.current_route: return

        is_waiting = False
        loc = self.current_route[self.progress_index]
        lat, lng = loc['lat'], loc['lng']

        # --- 1. SCHEDULE WAIT LOGIC ---
        if is_schedule and self.schedule_stops and self.next_stop_idx < len(self.schedule_stops):
            next_stop_id = self.schedule_stops[self.next_stop_idx]
            if next_stop_id in cached_stops:
                nx_lat, nx_lng = cached_stops[next_stop_id]
                dist = haversine_distance(lat, lng, nx_lat, nx_lng)
                
                if dist <= 0.05:
                    lat, lng = nx_lat, nx_lng 
                    if db_stop_index <= self.next_stop_idx:
                        self.process_boarding(task_id, next_stop_id)
                        is_waiting = True

        # --- 2. ON-DEMAND WAIT LOGIC ---
        if not is_schedule and task_id:
            dest_loc = self.current_route[-1]
            dist_to_dest = haversine_distance(lat, lng, dest_loc['lat'], dest_loc['lng'])
            
            if od_status in ['confirmed', 'arriving', 'arrived'] and dist_to_dest <= 0.05:
                lat, lng = dest_loc['lat'], dest_loc['lng'] 
                if not getattr(self, 'is_parked', False) or self.wait_ticks % 4 == 0:
                    log_to_ui(f"🚐 {self.shuttle_id} | 🛑 At Pickup. Waiting for driver to click 'START TRIP'...")
                self.is_parked = True
                self.wait_ticks += 1
                is_waiting = True
                
            elif od_status == 'onboard' and dist_to_dest <= 0.05:
                lat, lng = dest_loc['lat'], dest_loc['lng'] 
                if not getattr(self, 'is_parked', False) or self.wait_ticks % 4 == 0:
                    log_to_ui(f"🚐 {self.shuttle_id} | 🛑 At Dropoff. Waiting for Passenger QR Scan Checkout...")
                self.is_parked = True
                self.wait_ticks += 1
                is_waiting = True

        # --- 3. MOVEMENT ---
        if not is_waiting:
            self.progress_index += SPEED_MULTIPLIER
            if self.progress_index >= len(self.current_route):
                if self.mode == "ROAMING":
                    self.progress_index = 0 
                else:
                    self.progress_index = len(self.current_route) - 1

        loc = self.current_route[self.progress_index]
        lat, lng = loc['lat'], loc['lng']

        # --- 4. FIREBASE PUSH ---
        if self.mode != "ROAMING" and task_id:
            dest_loc = self.current_route[-1]
            dist_km = haversine_distance(lat, lng, dest_loc['lat'], dest_loc['lng'])
            hours = dist_km / SIMULATED_SPEED_KMH
            mins = math.ceil(hours * 60)
            self.eta_text = format_eta(mins)

            col = "Schedules" if is_schedule else "Bookings"
            try:
                db.collection(col).document(task_id).update({
                    "live_eta": self.eta_text,
                    "live_eta_updated_at": firestore.SERVER_TIMESTAMP
                })
            except Exception: pass

        try:
            db.collection("Shuttles").document(self.shuttle_id).update({
                "current_lat": lat, 
                "current_lng": lng,
                "last_updated": firestore.SERVER_TIMESTAMP
            })
            
            if not is_waiting:
                eta_log = f" | ETA: {self.eta_text}" if self.mode != "ROAMING" else ""
                log_to_ui(f"🚐 {self.shuttle_id} ({self.mode}){eta_log}")
        except Exception: pass

    def get_current_db_loc(self):
        try:
            d = db.collection("Shuttles").document(self.shuttle_id).get().to_dict()
            if d and 'current_lat' in d:
                return (d['current_lat'], d['current_lng'])
        except: pass
        return None

# ==========================================
# 4. CORE ENGINE & UI BINDING
# ==========================================
def init_roaming_routes():
    log_to_ui("\n--- 🗺️ MAPPING ROAMING ROUTES FOR ZONES ---")
    zone_stops = {}
    stops = db.collection("Stops").stream()
    for s in stops:
        d = s.to_dict()
        if 'lat' in d and 'lng' in d:
            zids = d.get('zone_ids') or ([d.get('zone_id')] if d.get('zone_id') else [])
            for zid in zids:
                if zid not in zone_stops: zone_stops[zid] = []
                zone_stops[zid].append((float(d['lat']), float(d['lng'])))

    for zid, coords in zone_stops.items():
        if len(coords) < 2: continue
        log_to_ui(f"📍 Calculating road path for Zone '{zid}'...")
        route = build_route_polyline(coords[0], coords[0], waypoints=coords[1:])
        if route:
            cached_zone_routes[zid] = route
            log_to_ui(f"   ✅ Success: Mapped {len(route)} points.")

def simulation_loop():
    global SIMULATOR_RUNNING, SELECTED_SHUTTLES
    fleet = {}
    
    while SIMULATOR_RUNNING:
        try:
            shuttles = db.collection("Shuttles").stream()
            online_count = 0
            
            for s in shuttles:
                sid = s.id
                
                if sid not in SELECTED_SHUTTLES:
                    continue

                data = s.to_dict()
                
                is_on = data.get("is_online", False)
                if isinstance(is_on, str): is_on = is_on.lower() in ['true', '1']
                
                if not is_on:
                    continue 
                
                if data.get("status", "").lower() == "maintenance":
                    log_to_ui(f"🔧 {sid} is under maintenance. Idling.")
                    continue
                    
                online_count += 1
                
                if sid not in fleet:
                    fleet[sid] = ShuttleState(sid)
                
                fleet[sid].update(data)
                
            if online_count == 0:
                log_to_ui(f"[{datetime.now().strftime('%H:%M:%S')}] 💤 No selected shuttles are currently online.")
                
            time.sleep(UPDATE_INTERVAL)
            
        except Exception as e:
            log_to_ui(f"\n💥 Crash: {e}")
            SIMULATOR_RUNNING = False
            break

# ==========================================
# 5. TKINTER UI
# ==========================================
def log_to_ui(msg):
    if text_area:
        text_area.insert(tk.END, msg + "\n")
        text_area.see(tk.END)

def select_all_shuttles():
    listbox_shuttles.select_set(0, tk.END)

def clear_all_shuttles():
    listbox_shuttles.select_clear(0, tk.END)

def start_simulation():
    global SIMULATOR_RUNNING, SELECTED_SHUTTLES
    if SIMULATOR_RUNNING: return
    
    selected_indices = listbox_shuttles.curselection()
    if not selected_indices:
        messagebox.showwarning("Warning", "Please select at least one shuttle to simulate.")
        return
        
    SELECTED_SHUTTLES = {listbox_shuttles.get(i) for i in selected_indices}

    SIMULATOR_RUNNING = True
    btn_start.config(state=tk.DISABLED)
    btn_stop.config(state=tk.NORMAL)
    listbox_shuttles.config(state=tk.DISABLED) 
    btn_select_all.config(state=tk.DISABLED) 
    btn_clear_all.config(state=tk.DISABLED)  
    
    log_to_ui(f"\n🚀 Starting Engine for: {', '.join(SELECTED_SHUTTLES)}")
    
    threading.Thread(target=simulation_loop, daemon=True).start()

def stop_simulation():
    global SIMULATOR_RUNNING
    SIMULATOR_RUNNING = False
    btn_start.config(state=tk.NORMAL)
    btn_stop.config(state=tk.DISABLED)
    listbox_shuttles.config(state=tk.NORMAL) 
    btn_select_all.config(state=tk.NORMAL)   
    btn_clear_all.config(state=tk.NORMAL)    
    log_to_ui("\n🛑 Engine Stopped.")

if __name__ == "__main__":
    print("Loading Maps & Shuttle data from Firebase...")
    fetch_all_stops()
    
    available_shuttles = fetch_available_shuttles()

    root = tk.Tk()
    root.title("CampusPulse Autonomous Engine")
    root.geometry("800x500")
    root.configure(padx=10, pady=10)

    frame_top = tk.Frame(root)
    frame_top.pack(fill=tk.X, pady=(0, 10))

    btn_start = tk.Button(frame_top, text="Start Engine", bg="#2ecc71", fg="white", font=("Helvetica", 10, "bold"), command=start_simulation)
    btn_start.pack(side=tk.LEFT, padx=(0, 10))

    btn_stop = tk.Button(frame_top, text="Stop Engine", bg="#e74c3c", fg="white", font=("Helvetica", 10, "bold"), command=stop_simulation, state=tk.DISABLED)
    btn_stop.pack(side=tk.LEFT)

    frame_body = tk.Frame(root)
    frame_body.pack(expand=True, fill=tk.BOTH)

    frame_list = tk.Frame(frame_body)
    frame_list.pack(side=tk.LEFT, fill=tk.Y, padx=(0, 10))
    
    tk.Label(frame_list, text="Target Shuttles:", font=("Helvetica", 10, "bold")).pack(anchor=tk.W, pady=(0, 5))
    
    frame_list_btns = tk.Frame(frame_list)
    frame_list_btns.pack(fill=tk.X, pady=(0, 5))
    
    btn_select_all = tk.Button(frame_list_btns, text="Select All", font=("Helvetica", 8), command=select_all_shuttles)
    btn_select_all.pack(side=tk.LEFT, expand=True, fill=tk.X, padx=(0, 2))
    
    btn_clear_all = tk.Button(frame_list_btns, text="Clear", font=("Helvetica", 8), command=clear_all_shuttles)
    btn_clear_all.pack(side=tk.LEFT, expand=True, fill=tk.X, padx=(2, 0))
    
    scrollbar_list = tk.Scrollbar(frame_list)
    scrollbar_list.pack(side=tk.RIGHT, fill=tk.Y)
    
    listbox_shuttles = tk.Listbox(frame_list, selectmode=tk.MULTIPLE, yscrollcommand=scrollbar_list.set, exportselection=False, width=20, font=("Helvetica", 10))
    listbox_shuttles.pack(side=tk.LEFT, fill=tk.Y)
    scrollbar_list.config(command=listbox_shuttles.yview)

    for sid in available_shuttles:
        listbox_shuttles.insert(tk.END, sid)

    text_area = scrolledtext.ScrolledText(frame_body, wrap=tk.WORD, font=("Consolas", 9), bg="#1e1e1e", fg="#00ff00")
    text_area.pack(side=tk.RIGHT, expand=True, fill=tk.BOTH)

    log_to_ui("CampusPulse UI Ready.")
    log_to_ui("1. Select one or more shuttles from the list on the left.")
    log_to_ui("2. Click 'Start Engine'.\n")

    root.after(100, init_roaming_routes)

    root.mainloop()