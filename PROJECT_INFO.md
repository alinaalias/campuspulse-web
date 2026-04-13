# CampusPulse - Project Context & Guidelines

## 1. Project Background & Overview
* **Context:** CampusPulse is a ride-hailing style, centralized transit platform tailored for the Universiti Kuala Lumpur (UniKL) community (hostel and off-campus residents).
* **Core Problem:** Eliminating "blind waiting" and inefficient campus transit caused by a lack of real-time management.
* **The Solution:** A hybrid shuttle booking system that combines fixed-schedule reliability with on-demand flexibility.
* **Primary Platforms:** * **Web Portal:** Used by Administrators (management/analytics) and Drivers (manifests/execution/GPS broadcasting).
    * **Mobile Application (Flutter):** Used by Students for booking, tracking, and boarding.

## 2. Technical Stack & Architecture
* **Frontend (Web Portal):** HTML5, CSS3, Vanilla JavaScript.
* **Backend:** PHP 8+ (session-based authentication, role-based access).
* **Database:** Firebase Firestore (NoSQL).
* **Cloud Storage:** Google Cloud Storage (for images and application documents).
* **External Integrations:** Google Maps API (routing/tracking/radar), HTML5-QRCode (scanning), Firebase JS SDK (real-time listeners).

## 3. System Roles & Core Modules
* **Administrators (Web Portal):**
    * *Admin Management:* Oversee student/driver records, vehicle registration, zones, and stops.
    * *Live Fleet Radar:* Monitor all active shuttles in real-time on a unified map dashboard.
    * *Shuttle Scheduling:* Create master timetables and assign drivers/vehicles using auto-generation logic with "deadhead" return trip accounting.
    * *Service Announcements:* Broadcast alerts (route changes, emergencies).
    * *Analytics & Reporting:* Track shuttle utilization rates, popular routes, and peak-hour trends to optimize fleet operations.
* **Drivers (Web Portal):**
    * *Trip Execution:* View assigned routes and passenger manifests.
    * *Live GPS Heartbeat:* Automatically broadcast live coordinates to Firestore every 10 seconds via the Browser Geolocation API while online.
    * *QR Boarding Verification:* Use instant QR scanning to verify student boarding, ensuring only authorized passengers board and automatically updating live capacity counts.
* **Students (Mobile App - Context Only):**
    * *Authentication:* Strict verification using **UniKL email domains**.
    * *Booking & Penalties:* Execute hybrid bookings. Subject to penalty logic for no-shows or late cancellations.
    * *Smart Recommendations:* Receives optimal departure times based on class schedules and traffic data.
    * *Live Tracking:* Map-based visualization of shuttle locations and ETAs.

## 4. Firestore Database Schema Structure (Strict Reference)
*Note: Because this is a NoSQL database, fields may vary between documents. These are the expected baseline fields.*

* **`DriverApplications` Collection:** (Document ID: Auto-generated)
    * `full_name`, `ic_number`, `gender`, `dob` (YYYY-MM-DD), `email`, `phone_number`, `home_address`, `license_number`, `license_expiry`, `psv_expiry`, `years_experience`, `doc_profile_pic`, `doc_ic`, `doc_license`, `doc_psv`, `decl_clean_record`, `decl_health_ok`, `status` ('pending', 'accepted', 'rejected'), `applied_at`.
* **`Bookings`**
    * `bookingId`, `booking_time`,`check_in_time`, `date`, `departure_time`, `driver_id`, `driver_name`, `route_id`, `route_name`, `schedule_id`, `shuttle_id`, `status` (e.g., 'onboard'), `student_id`, `type` (e.g., 'scheduled' or 'on-demand'), `ticket_status`, `zone_id`.
* **`Ratings`**
    * `comments`, `created_at`, `driver_id`, `rating` (number), `student_id`.
* **`Routes`**
    * `created_at`, `direction`, `end_stop_id`, `route_id`, `route_name`, `service_type`, `start_stop_id`, `status`, `updated_at`, `stops_ids` (array of maps with `stop_id` and `offset`).
* **`Schedules`**
    * `booked_count`, `capacity`, `created_at`, `date` (YYYY-MM-DD), `departure_time`, `driver_id`, `end_stop_id`, `onboard_count`, `peak` ('morning', 'evening'), `route_id`, `schedule_id`, `shuttle_id`, `start_stop_id`, `status`, `etas` (Map: `stop_id` -> `time_string`).
* **`Shuttles`** (Document ID: e.g., 'CPS027')
    * `shuttle_id` (string), `capacity` (int64), `zone_id` (string), `status` (string), `created_at` (string), `updated_at` (string).
    * **Live Radar Fields:** `current_lat` (number), `current_lng` (number), `is_online` (boolean), `last_updated` (timestamp/string).
* **`Staffs`** (Admins & Drivers)
    * `created_at`, `email`, `full_name`, `password` (hashed), `phone_number`, `role` ('admin' or 'driver'), `status`, `updated_at`.
    * **Driver Specific:** `assigned_shuttle_id`, `duty_status` ('online', 'offline'), `ic_number`, `license_number`, `profile_pic` (path), `last_alert_read_time` (int64).
    * **Admin Specific:** `photo_url`.
* **`Students`**
    * `registration_date`, `student_email` (Must be UniKL domain), `full_name`, `has_completed_profile`, `phone_number`, `photo_url`, `student_id`, `status`, `username`, `uid`. 
* **`Zones`**
    * `center_point` (geopoint), `created_at`, `name`, `status`, `zone_id`.

## 5. UI/UX & Design Principles
* Minimalist design to reduce cognitive load. Card-based layouts (`.driver-card`, `.card`).
* **Fonts/Icons:** Poppins font family, FontAwesome 6 icons.
* **Buttons:** `.btn-save`, `.btn-logout`, `.btn-home`, `.btn-scan`.
* **Alignment:** Strict horizontal alignment for filters and dashboard controls.

## 6. AI Agent Development Directives
* **Strict Schema Adherence:** Always use the exact Firestore collection and field names defined above. Do not invent new fields. 
* **Business Logic Awareness:** Remember penalty logic, UniKL email restrictions, and capacity updating rules when writing functions.
* **Security & Sessions:** All protected PHP pages must begin with `session_start()` and validate `$_SESSION['role']`.
* **Token Efficiency:** When modifying code, output only the specific functions or HTML blocks requested. Do not rewrite whole files unless asked.
* **Error Handling:** Ensure robust `try/catch` blocks are used for all Firebase/Firestore operations.
* **NoSQL Safe Reading:** Because Firestore documents may have varying fields, you MUST use the PHP null coalescing operator (`??`) or `isset()` when reading array keys from a document snapshot to prevent "Undefined array key" errors. (e.g., `$driverName = $doc['full_name'] ?? 'Unknown';`).