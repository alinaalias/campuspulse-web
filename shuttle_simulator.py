import time
import firebase_admin
from firebase_admin import credentials
from firebase_admin import firestore

# 1. Initialize Firebase
# Replace 'firebase-key.json' with the path to your downloaded key
key_path = r"C:\xampp\htdocs\FYP\service-account.json"
cred = credentials.Certificate(key_path)
firebase_admin.initialize_app(cred)

db = firestore.client()

# 2. Define your Test Variables
# Use the actual Shuttle ID from your database
SHUTTLE_ID = 'CPS003' 

# Define a fake route (Latitude, Longitude)
# You can grab these from Google Maps by right-clicking anywhere on the map
fake_route = [
    {"lat": 3.1592, "lng": 101.7036}, # Stop 1
    {"lat": 3.1595, "lng": 101.7040}, # Moving...
    {"lat": 3.1600, "lng": 101.7045}, # Moving...
    {"lat": 3.1605, "lng": 101.7050}, # Stop 2
    {"lat": 3.1610, "lng": 101.7055}, # Moving...
    {"lat": 3.1615, "lng": 101.7060}  # Stop 3
]

print(f"🚌 Starting Simulation for Shuttle: {SHUTTLE_ID}")

# 3. Loop through the route and update Firestore
for index, location in enumerate(fake_route):
    print(f"Moving to point {index + 1}: Lat {location['lat']}, Lng {location['lng']}")
    
    # Update the Shuttles collection just like your JS does
    db.collection('Shuttles').document(SHUTTLE_ID).set({
        'current_lat': location['lat'],
        'current_lng': location['lng'],
        'is_online': True,
        'last_updated': firestore.SERVER_TIMESTAMP
    }, merge=True)
    
    # Wait 5 seconds before moving to the next point
    time.sleep(5)

print("🏁 Route Complete! Shuttle has arrived at final destination.")