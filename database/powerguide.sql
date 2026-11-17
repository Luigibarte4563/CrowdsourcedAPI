-- =========================================================
-- POWERGUIDE DAGUPAN
-- Smart Blackout Survival and Resource Management System
-- Normalized Database
-- =========================================================

DROP DATABASE IF EXISTS powerguide;

CREATE DATABASE powerguide
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE powerguide;


-- =========================================================
-- 1. BARANGAYS
-- =========================================================

CREATE TABLE barangays (
    id INT AUTO_INCREMENT PRIMARY KEY,

    barangay_name VARCHAR(150) NOT NULL UNIQUE,

    city VARCHAR(150) NOT NULL DEFAULT 'Dagupan City',

    province VARCHAR(150) NOT NULL DEFAULT 'Pangasinan',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =========================================================
-- 2. USERS
-- =========================================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,

    google_id VARCHAR(100) UNIQUE NULL,

    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,

    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NULL,

    picture TEXT NULL,

    auth_provider ENUM(
        'local',
        'google'
    ) NOT NULL DEFAULT 'local',

    role ENUM('user','lineman','electric_company','admin') NOT NULL DEFAULT 'user',

    barangay_id INT NULL,

    address VARCHAR(255) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    location_updated_at TIMESTAMP NULL,

    /* refresh_token must store a HASH of the token, never plain text. */
    refresh_token TEXT NULL,
    last_login TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_users_role
ON users(role);

CREATE INDEX idx_users_coordinates
ON users(latitude, longitude);


-- =========================================================
-- 3. OUTAGE REPORTS
-- =========================================================

CREATE TABLE outage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    barangay_id INT NULL,

    category ENUM('power_outage','low_voltage','power_fluctuation',
        'transformer_explosion','fallen_power_line','electrical_fire',
        'scheduled_maintenance','unknown_issue') NOT NULL,

    severity ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'low',

    hazard_type ENUM('none','smoke','sparks','fire','fallen_wire',
        'explosion_sound','submerged_electrical_equipment','low_hanging_cable',
        'exposed_wire','electrical_shock_risk') NOT NULL DEFAULT 'none',

    status ENUM('active','under_review','verified','resolved','rejected') NOT NULL DEFAULT 'active',

    report_key VARCHAR(255) NOT NULL UNIQUE,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    description TEXT NULL,

    started_at DATETIME NULL,

    resolved_at DATETIME NULL,

    resolution_note TEXT NULL,

    updated_by INT NULL,

    update_message TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL,

    FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_outage_coordinates
ON outage_reports(latitude, longitude);

CREATE INDEX idx_outage_barangay
ON outage_reports(barangay_id);

CREATE INDEX idx_outage_status
ON outage_reports(status);

CREATE INDEX idx_outage_created
ON outage_reports(created_at);


-- =========================================================
-- 4. OUTAGE REPORT IMAGES
-- =========================================================

CREATE TABLE outage_report_images (
    id INT AUTO_INCREMENT PRIMARY KEY,

    outage_report_id INT NOT NULL,

    uploaded_by INT NOT NULL,

    image_url TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (outage_report_id)
        REFERENCES outage_reports(id)
        ON DELETE CASCADE,

    FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE CASCADE
);


-- =========================================================
-- 5. OUTAGE REPORT VERIFICATIONS
-- =========================================================

CREATE TABLE outage_report_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,

    outage_report_id INT NOT NULL,

    verified_by INT NOT NULL,

    verification_status ENUM(
        'confirmed',
        'not_confirmed',
        'false_report'
    ) NOT NULL,

    notes TEXT NULL,

    verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (outage_report_id)
        REFERENCES outage_reports(id)
        ON DELETE CASCADE,

    FOREIGN KEY (verified_by)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_verifications_report
ON outage_report_verifications(outage_report_id);


-- =========================================================
-- 6. OUTAGE SPATIAL CLUSTERS
-- =========================================================

CREATE TABLE outage_clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,

    barangay_id INT NOT NULL,

    cluster_date DATE NOT NULL,

    center_latitude DECIMAL(10,8) NOT NULL,

    center_longitude DECIMAL(11,8) NOT NULL,

    radius_meters INT NOT NULL DEFAULT 500,

    report_count INT NOT NULL DEFAULT 0,

    confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    severity_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    forecast_level ENUM(
        'low',
        'moderate',
        'high',
        'critical'
    ) NOT NULL DEFAULT 'low',

    status ENUM(
        'active',
        'expired'
    ) NOT NULL DEFAULT 'active',

    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_clusters_barangay
ON outage_clusters(barangay_id);

CREATE INDEX idx_clusters_coordinates
ON outage_clusters(center_latitude, center_longitude);

CREATE INDEX idx_clusters_confidence
ON outage_clusters(confidence_score);


-- =========================================================
-- 7. MAINTENANCE SCHEDULES
-- =========================================================

CREATE TABLE maintenance_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,

    created_by INT NOT NULL,

    maintenance_date DATE NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    radius INT DEFAULT 2000,

    description TEXT NULL,

    estimated_restoration_time DATETIME NULL,

    status ENUM(
        'upcoming',
        'ongoing',
        'completed',
        'cancelled'
    ) DEFAULT 'upcoming',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
);


-- =========================================================
-- 8. MAINTENANCE LOCATIONS
-- =========================================================

CREATE TABLE maintenance_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    maintenance_id INT NOT NULL,

    barangay_id INT NOT NULL,

    latitude DECIMAL(10,8) NULL,

    longitude DECIMAL(11,8) NULL,

    FOREIGN KEY (maintenance_id)
        REFERENCES maintenance_schedules(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_maintenance_barangay
ON maintenance_locations(barangay_id);


-- =========================================================
-- 9. POWER STATIONS
-- =========================================================

CREATE TABLE power_stations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    created_by INT NOT NULL,

    barangay_id INT NULL,

    station_type ENUM('power_station','solar_station','charging_station','generator_station') NOT NULL,

    station_name VARCHAR(255) NOT NULL,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8) NULL,

    longitude DECIMAL(11,8) NULL,

    access_type ENUM(
        'free',
        'paid'
    ) DEFAULT 'free',

    availability_status ENUM(
        'available',
        'busy',
        'offline',
        'maintenance'
    ) DEFAULT 'available',

    opening_time TIME NULL,
    closing_time TIME NULL,
    is_24_hours BOOLEAN DEFAULT FALSE,

    charging_type ENUM('usb','outlet','fast_charge','solar','mixed') NULL,

    description TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_station_location
ON power_stations(latitude, longitude);

CREATE INDEX idx_station_availability
ON power_stations(availability_status);


-- =========================================================
-- 10. BATTERY DEVICES
-- =========================================================

CREATE TABLE battery_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    device_name VARCHAR(150) NOT NULL,

    device_type ENUM(
        'phone',
        'laptop',
        'powerbank',
        'ups',
        'tablet',
        'other'
    ) NOT NULL,

    capacity_mah INT NULL,

    current_percentage DECIMAL(5,2) DEFAULT 100.00,

    is_primary BOOLEAN DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_battery_user
ON battery_devices(user_id);


-- =========================================================
-- 11. BATTERY USAGE LOGS
-- =========================================================

CREATE TABLE battery_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,

    battery_device_id INT NOT NULL,

    battery_percentage_start DECIMAL(5,2) NOT NULL,

    battery_percentage_end DECIMAL(5,2) NOT NULL,

    usage_minutes INT NULL,

    estimated_watts DECIMAL(8,2) NULL,

    activity VARCHAR(150) NULL,

    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (battery_device_id)
        REFERENCES battery_devices(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_battery_usage_device
ON battery_usage_logs(battery_device_id);


-- =========================================================
-- 12. FLOOD REPORTS
-- =========================================================

CREATE TABLE flood_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,

    reported_by INT NOT NULL,

    barangay_id INT NULL,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8) NOT NULL,

    longitude DECIMAL(11,8) NOT NULL,

    flood_depth_cm DECIMAL(8,2) NULL,

    severity ENUM(
        'low',
        'moderate',
        'high',
        'critical'
    ) NOT NULL DEFAULT 'low',

    description TEXT NULL,

    image_proof TEXT NULL,

    status ENUM(
        'reported',
        'verified',
        'cleared'
    ) DEFAULT 'reported',

    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_by INT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (reported_by)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL,

    FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_flood_coordinates
ON flood_reports(latitude, longitude);

CREATE INDEX idx_flood_barangay
ON flood_reports(barangay_id);


-- =========================================================
-- 13. ELECTRICAL HAZARDS
-- =========================================================

CREATE TABLE electrical_hazards (
    id INT AUTO_INCREMENT PRIMARY KEY,

    reported_by INT NOT NULL,

    barangay_id INT NULL,

    hazard_type ENUM('none','smoke','sparks','fire','fallen_wire',
        'explosion_sound','submerged_electrical_equipment','low_hanging_cable',
        'exposed_wire','electrical_shock_risk') NOT NULL,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8) NOT NULL,

    longitude DECIMAL(11,8) NOT NULL,

    description TEXT NULL,

    severity ENUM(
        'low',
        'moderate',
        'high',
        'critical'
    ) DEFAULT 'moderate',

    status ENUM(
        'reported',
        'verified',
        'resolved'
    ) DEFAULT 'reported',

    image_proof TEXT NULL,

    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    resolved_at DATETIME NULL,

    updated_by INT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (reported_by)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL,

    FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_electrical_hazard_coordinates
ON electrical_hazards(latitude, longitude);

CREATE INDEX idx_electrical_hazard_barangay
ON electrical_hazards(barangay_id);


-- =========================================================
-- 14. SAFETY TIMERS
-- =========================================================

CREATE TABLE safety_timers (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    timer_type ENUM('sealed_refrigerator','deep_freezer_24h','deep_freezer_48h','medication') NOT NULL,

    title VARCHAR(150) NOT NULL,

    started_at DATETIME NOT NULL,

    expected_expiration_at DATETIME NOT NULL,

    warning_at DATETIME NULL,

    warning_sent_at DATETIME NULL,

    expired_sent_at DATETIME NULL,

    completed_at DATETIME NULL,

    status ENUM(
        'running',
        'warning',
        'expired',
        'stopped'
    ) DEFAULT 'running',

    notes TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_safety_timer_user
ON safety_timers(user_id);

CREATE INDEX idx_safety_timer_expiration
ON safety_timers(expected_expiration_at);


-- =========================================================
-- 15. NOTIFICATIONS
-- =========================================================

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    notification_type ENUM('maintenance','outage','emergency','flood','electrical_hazard','battery','safety_timer','system') NOT NULL,

    title VARCHAR(255) NOT NULL,

    message TEXT NOT NULL,

    is_read BOOLEAN DEFAULT FALSE,

    outage_report_id INT NULL,

    maintenance_id INT NULL,

    flood_report_id INT NULL,

    electrical_hazard_id INT NULL,

    safety_timer_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (outage_report_id)
        REFERENCES outage_reports(id)
        ON DELETE CASCADE,

    FOREIGN KEY (maintenance_id)
        REFERENCES maintenance_schedules(id)
        ON DELETE CASCADE,

    FOREIGN KEY (flood_report_id)
        REFERENCES flood_reports(id)
        ON DELETE CASCADE,

    FOREIGN KEY (electrical_hazard_id)
        REFERENCES electrical_hazards(id)
        ON DELETE CASCADE,

    FOREIGN KEY (safety_timer_id)
        REFERENCES safety_timers(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_notifications_user
ON notifications(user_id);

CREATE INDEX idx_notifications_read
ON notifications(is_read);

CREATE INDEX idx_notifications_user_read
ON notifications(user_id, is_read);


-- =========================================================
-- 16. LINEMAN ASSIGNMENTS
-- =========================================================

CREATE TABLE lineman_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,

    lineman_id INT NOT NULL,

    barangay_id INT NOT NULL,

    /* Written by the backend from the JWT identity, never from the request. */
    assigned_by INT NOT NULL,

    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (lineman_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (assigned_by)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    UNIQUE KEY uq_lineman_assignments_pair (lineman_id, barangay_id)
);

CREATE INDEX idx_lineman_assignments_lineman_status
ON lineman_assignments(lineman_id, status);

CREATE INDEX idx_lineman_assignments_barangay
ON lineman_assignments(barangay_id);


-- =========================================================
-- 17. DAGUPAN CITY BARANGAYS
-- =========================================================

-- Coordinates make the barangay the source of truth for a record's position:
-- a report's point is its barangay's centroid, and the address text is only a
-- label. Coordinates are baked in here; barangays still awaiting a hand-entered
-- value are left NULL on purpose rather than guessed at, and the API rejects a
-- report in a barangay whose coordinates are missing.
--
-- The 31 barangays below match the official Philippine Standard Geographic Code
-- (PSGC) list for City of Dagupan, Pangasinan (as of 31 July 2025). Barangays
-- that appeared in an older draft but are NOT official Dagupan barangays were
-- removed: Bacag (in Urdaneta), Balsadan, Barangay III (only I, II, IV exist),
-- Dapdap, Dumalneg (a municipality in Ilocos Norte), Poblacion Este, Sapanglang,
-- Tippler. 'Poblacion' was a duplicate of 'Poblacion Oeste' and removed.
-- Missing official barangays added: Pogo Chico, Pogo Grande, Pugaro Suit.
-- Barangay I, II, and IV remain NULL - exact coordinates could not be verified
-- from an authoritative source and are intentionally not guessed.
INSERT INTO barangays (barangay_name, latitude, longitude) VALUES
('Bacayao Norte', 16.0321184, 120.343659),
('Bacayao Sur', 16.0224982, 120.3460497),
('Barangay I', NULL, NULL),
('Barangay II', NULL, NULL),
('Barangay IV', NULL, NULL),
('Bolosan', 16.0470283, 120.3653888),
('Bonuan Binloc', 16.1019298, 120.3797034),
('Bonuan Boquig', 16.0774984, 120.3580251),
('Bonuan Gueset', 16.0753807, 120.3431786),
('Calmay', 16.0452879, 120.325569),
('Carael', 16.05, 120.31667),
('Caranglaan', 16.0306481, 120.3499153),
('Herrero', 16.0439297, 120.3422628),
('Lasip Chico', 16.0216379, 120.3402722),
('Lasip Grande', 16.0286206, 120.3432008),
('Lomboy', 16.0548499, 120.3248795),
('Lucao', 16.0204862, 120.3226274),
('Malued', 16.0292908, 120.3351235),
('Mamalingling', 16.0534043, 120.3615684),
('Mangin', 16.0388765, 120.3673354),
('Mayombo', 16.0418406, 120.346308),
('Pantal', 16.0461741, 120.3387457),
('Poblacion Oeste', 16.0432792, 120.3295612),
('Pogo Chico', NULL, NULL),
('Pogo Grande', NULL, NULL),
('Pugaro Suit', NULL, NULL),
('Salapingao', 16.0589914, 120.3217036),
('Salisay', 16.0423396, 120.3714122),
('Tambac', 16.046036, 120.3563194),
('Tapuac', 16.0323968, 120.3302149),
('Tebeng', 16.0320226, 120.3588773);


-- =========================================================
-- END OF DATABASE
-- =========================================================
