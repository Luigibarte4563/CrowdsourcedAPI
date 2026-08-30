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
-- 1. ROLES
-- =========================================================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO roles (role_name, description) VALUES
('user', 'Regular PowerGuide user'),
('lineman', 'DECORP field personnel'),
('electric_company', 'DECORP/electric company personnel'),
('admin', 'System administrator');


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

    role_id INT NOT NULL,

    refresh_token TEXT NULL,
    last_login TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_users_role
ON users(role_id);


-- =========================================================
-- 3. BARANGAYS
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
-- 4. USER LOCATIONS
-- =========================================================

CREATE TABLE user_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    barangay_id INT NULL,

    address VARCHAR(255) NULL,

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    is_primary BOOLEAN DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_user_locations_coordinates
ON user_locations(latitude, longitude);

CREATE INDEX idx_user_locations_barangay
ON user_locations(barangay_id);


-- =========================================================
-- 5. OUTAGE CATEGORIES
-- =========================================================

CREATE TABLE outage_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,

    category_name VARCHAR(100) NOT NULL UNIQUE,

    description VARCHAR(255) NULL
);

INSERT INTO outage_categories
(category_name, description)
VALUES
('power_outage', 'Complete loss of electricity'),
('low_voltage', 'Low voltage condition'),
('power_fluctuation', 'Unstable electrical supply'),
('transformer_explosion', 'Transformer explosion'),
('fallen_power_line', 'Fallen power line'),
('electrical_fire', 'Electrical fire'),
('scheduled_maintenance', 'Scheduled electrical maintenance'),
('unknown_issue', 'Unknown electrical issue');


-- =========================================================
-- 6. SEVERITY LEVELS
-- =========================================================

CREATE TABLE severity_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,

    severity_name VARCHAR(50) NOT NULL UNIQUE,

    priority INT NOT NULL
);

INSERT INTO severity_levels
(severity_name, priority)
VALUES
('minor', 1),
('moderate', 2),
('critical', 3);


-- =========================================================
-- 7. HAZARD TYPES
-- =========================================================

CREATE TABLE hazard_types (
    id INT AUTO_INCREMENT PRIMARY KEY,

    hazard_name VARCHAR(100) NOT NULL UNIQUE,

    description VARCHAR(255) NULL
);

INSERT INTO hazard_types
(hazard_name, description)
VALUES
('none', 'No additional hazard'),
('smoke', 'Smoke detected'),
('sparks', 'Electrical sparks'),
('fire', 'Electrical fire'),
('fallen_wire', 'Fallen electrical wire'),
('explosion_sound', 'Explosion sound'),
('submerged_electrical_equipment', 'Electrical equipment submerged in flood water'),
('low_hanging_cable', 'Low-hanging electrical cable'),
('exposed_wire', 'Exposed electrical wire'),
('electrical_shock_risk', 'Potential electrocution risk');


-- =========================================================
-- 8. OUTAGE STATUSES
-- =========================================================

CREATE TABLE outage_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,

    status_name VARCHAR(50) NOT NULL UNIQUE,

    description VARCHAR(255) NULL
);

INSERT INTO outage_statuses
(status_name, description)
VALUES
('active', 'Currently active report'),
('under_review', 'Being reviewed'),
('verified', 'Verified by authorized personnel'),
('resolved', 'Issue has been resolved'),
('rejected', 'Report was rejected');


-- =========================================================
-- 9. OUTAGE REPORTS
-- =========================================================

CREATE TABLE outage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    barangay_id INT NULL,

    category_id INT NOT NULL,

    severity_id INT NOT NULL,

    hazard_type_id INT NOT NULL,

    status_id INT NOT NULL,

    report_key VARCHAR(255) NOT NULL UNIQUE,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    description TEXT NULL,

    affected_houses INT DEFAULT 1,

    is_active BOOLEAN DEFAULT TRUE,

    started_at DATETIME NULL,

    resolved_at DATETIME NULL,

    resolution_note TEXT NULL,

    maintenance_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL,

    FOREIGN KEY (category_id)
        REFERENCES outage_categories(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (severity_id)
        REFERENCES severity_levels(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (hazard_type_id)
        REFERENCES hazard_types(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (status_id)
        REFERENCES outage_statuses(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_outage_coordinates
ON outage_reports(latitude, longitude);

CREATE INDEX idx_outage_barangay
ON outage_reports(barangay_id);

CREATE INDEX idx_outage_status
ON outage_reports(status_id);

CREATE INDEX idx_outage_created
ON outage_reports(created_at);


-- =========================================================
-- 10. OUTAGE REPORT IMAGES
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
-- 11. OUTAGE REPORT UPDATES
-- =========================================================

CREATE TABLE outage_report_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,

    outage_report_id INT NOT NULL,

    updated_by INT NOT NULL,

    status_id INT NULL,

    update_message TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (outage_report_id)
        REFERENCES outage_reports(id)
        ON DELETE CASCADE,

    FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (status_id)
        REFERENCES outage_statuses(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_outage_updates_report
ON outage_report_updates(outage_report_id);


-- =========================================================
-- 12. OUTAGE REPORT VERIFICATIONS
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
-- 13. OUTAGE SPATIAL CLUSTERS
-- =========================================================

CREATE TABLE outage_clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,

    barangay_id INT NOT NULL,

    cluster_date DATE NOT NULL,

    center_latitude DECIMAL(10,8) NOT NULL,

    center_longitude DECIMAL(11,8) NOT NULL,

    radius_meters INT NOT NULL DEFAULT 500,

    report_count INT NOT NULL DEFAULT 0,

    affected_houses INT NOT NULL DEFAULT 0,

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
-- 14. OUTAGE CLUSTER REPORTS
-- =========================================================

CREATE TABLE outage_cluster_reports (
    cluster_id INT NOT NULL,

    outage_report_id INT NOT NULL,

    distance_meters DECIMAL(10,2) NULL,

    PRIMARY KEY (
        cluster_id,
        outage_report_id
    ),

    FOREIGN KEY (cluster_id)
        REFERENCES outage_clusters(id)
        ON DELETE CASCADE,

    FOREIGN KEY (outage_report_id)
        REFERENCES outage_reports(id)
        ON DELETE CASCADE
);


-- =========================================================
-- 15. MAINTENANCE SCHEDULES
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
-- 16. MAINTENANCE LOCATIONS
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
-- 17. POWER STATION TYPES
-- =========================================================

CREATE TABLE power_station_types (
    id INT AUTO_INCREMENT PRIMARY KEY,

    type_name VARCHAR(100) NOT NULL UNIQUE
);

INSERT INTO power_station_types
(type_name)
VALUES
('power_station'),
('solar_station'),
('charging_station'),
('generator_station');


-- =========================================================
-- 18. POWER STATIONS
-- =========================================================

CREATE TABLE power_stations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    created_by INT NOT NULL,

    barangay_id INT NULL,

    station_type_id INT NOT NULL,

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

    operating_hours VARCHAR(100) NULL,

    charging_type VARCHAR(100) NULL,

    description TEXT NULL,

    image TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL,

    FOREIGN KEY (station_type_id)
        REFERENCES power_station_types(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_station_location
ON power_stations(latitude, longitude);

CREATE INDEX idx_station_availability
ON power_stations(availability_status);


-- =========================================================
-- 19. BATTERY DEVICES
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
-- 20. BATTERY USAGE LOGS
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
-- 21. FLOOD REPORTS
-- =========================================================

CREATE TABLE flood_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,

    reported_by INT NOT NULL,

    barangay_id INT NULL,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8) NOT NULL,

    longitude DECIMAL(11,8) NOT NULL,

    flood_depth_cm DECIMAL(8,2) NULL,

    flood_level ENUM(
        'low',
        'moderate',
        'high',
        'severe'
    ) NOT NULL DEFAULT 'low',

    description TEXT NULL,

    image_proof TEXT NULL,

    status ENUM(
        'reported',
        'verified',
        'cleared'
    ) DEFAULT 'reported',

    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (reported_by)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_flood_coordinates
ON flood_reports(latitude, longitude);

CREATE INDEX idx_flood_barangay
ON flood_reports(barangay_id);


-- =========================================================
-- 22. ELECTRICAL HAZARDS
-- =========================================================

CREATE TABLE electrical_hazards (
    id INT AUTO_INCREMENT PRIMARY KEY,

    reported_by INT NOT NULL,

    barangay_id INT NULL,

    hazard_type_id INT NOT NULL,

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

    FOREIGN KEY (reported_by)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (barangay_id)
        REFERENCES barangays(id)
        ON DELETE SET NULL,

    FOREIGN KEY (hazard_type_id)
        REFERENCES hazard_types(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_electrical_hazard_coordinates
ON electrical_hazards(latitude, longitude);

CREATE INDEX idx_electrical_hazard_barangay
ON electrical_hazards(barangay_id);


-- =========================================================
-- 23. SAFETY TIMER TYPES
-- =========================================================

CREATE TABLE safety_timer_types (
    id INT AUTO_INCREMENT PRIMARY KEY,

    timer_name VARCHAR(100) NOT NULL UNIQUE,

    default_duration_hours DECIMAL(6,2) NOT NULL,

    warning_hours_before DECIMAL(6,2) NOT NULL,

    description VARCHAR(255) NULL
);

INSERT INTO safety_timer_types
(
    timer_name,
    default_duration_hours,
    warning_hours_before,
    description
)
VALUES
(
    'sealed_refrigerator',
    4,
    1,
    'Food safety timer for a sealed refrigerator'
),
(
    'deep_freezer_24h',
    24,
    4,
    'Conservative deep freezer safety timer'
),
(
    'deep_freezer_48h',
    48,
    6,
    'Extended deep freezer safety timer'
),
(
    'medication',
    4,
    1,
    'Medication temperature safety timer'
);


-- =========================================================
-- 24. SAFETY TIMERS
-- =========================================================

CREATE TABLE safety_timers (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    timer_type_id INT NOT NULL,

    title VARCHAR(150) NOT NULL,

    started_at DATETIME NOT NULL,

    expected_expiration_at DATETIME NOT NULL,

    warning_at DATETIME NULL,

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
        ON DELETE CASCADE,

    FOREIGN KEY (timer_type_id)
        REFERENCES safety_timer_types(id)
        ON DELETE RESTRICT
);

CREATE INDEX idx_safety_timer_user
ON safety_timers(user_id);

CREATE INDEX idx_safety_timer_expiration
ON safety_timers(expected_expiration_at);


-- =========================================================
-- 25. SAFETY TIMER ALERTS
-- =========================================================

CREATE TABLE safety_timer_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    safety_timer_id INT NOT NULL,

    alert_type ENUM(
        'warning',
        'expired'
    ) NOT NULL,

    sent_at DATETIME NULL,

    is_sent BOOLEAN DEFAULT FALSE,

    FOREIGN KEY (safety_timer_id)
        REFERENCES safety_timers(id)
        ON DELETE CASCADE
);


-- =========================================================
-- 26. NOTIFICATION TYPES
-- =========================================================

CREATE TABLE notification_types (
    id INT AUTO_INCREMENT PRIMARY KEY,

    type_name VARCHAR(100) NOT NULL UNIQUE
);

INSERT INTO notification_types
(type_name)
VALUES
('maintenance'),
('outage'),
('emergency'),
('flood'),
('electrical_hazard'),
('battery'),
('safety_timer'),
('system');


-- =========================================================
-- 27. NOTIFICATIONS
-- =========================================================

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    notification_type_id INT NOT NULL,

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

    FOREIGN KEY (notification_type_id)
        REFERENCES notification_types(id)
        ON DELETE RESTRICT,

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


-- =========================================================
-- 28. DAGUPAN CITY BARANGAYS
-- =========================================================

INSERT INTO barangays (barangay_name) VALUES
('Bacayao Norte'),
('Bacayao Sur'),
('Bacag'),
('Balsadan'),
('Barangay I'),
('Barangay II'),
('Barangay III'),
('Barangay IV'),
('Bolosan'),
('Bonuan Binloc'),
('Bonuan Boquig'),
('Bonuan Gueset'),
('Calmay'),
('Carael'),
('Caranglaan'),
('Dapdap'),
('Dumalneg'),
('Herrero'),
('Lasip Chico'),
('Lasip Grande'),
('Lomboy'),
('Lucao'),
('Malued'),
('Mamalingling'),
('Mangin'),
('Mayombo'),
('Pantal'),
('Poblacion Oeste'),
('Poblacion Este'),
('Salapingao'),
('Salisay'),
('Sapanglang'),
('Tambac'),
('Tebeng'),
('Tippler'),
('Tapuac');


-- =========================================================
-- END OF DATABASE
-- =========================================================