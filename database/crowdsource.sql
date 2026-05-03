CREATE DATABASE powerguide;

USE powerguide;

CREATE TABLE outage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,

    location_name VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),

    category ENUM(
        'power_outage',
        'low_voltage',
        'power_fluctuation',
        'transformer_explosion',
        'fallen_power_line',
        'electrical_fire',
        'scheduled_maintenance',
        'unknown_issue'
    ) DEFAULT 'power_outage',

    severity ENUM('minor','moderate','critical') DEFAULT 'moderate',

    description TEXT,
    image_proof TEXT NULL,
    affected_houses INT DEFAULT 1,

    is_active ENUM('yes','no','unknown') DEFAULT 'yes',

    hazard_type ENUM('none','smoke','sparks','fire','fallen_wire','explosion_sound') DEFAULT 'none',

    started_at DATETIME NULL,

    status ENUM('unverified','under_review','verified','resolved','fake_report') DEFAULT 'unverified',

    verified_by INT NULL,

    is_deleted TINYINT(1) DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_user_id ON outage_reports(user_id);
CREATE INDEX idx_status ON outage_reports(status);
CREATE INDEX idx_location ON outage_reports(latitude, longitude);
CREATE INDEX idx_status_category ON outage_reports(status, category);



CREATE TABLE power_stations (

    id INT AUTO_INCREMENT PRIMARY KEY,

    created_by INT NULL,

    station_name VARCHAR(255) NOT NULL,

    location_name VARCHAR(255) NOT NULL,

    latitude DECIMAL(10,8),

    longitude DECIMAL(11,8),

    station_type ENUM(
        'power_station',
        'solar_station',
        'charging_station',
        'generator_station'
    ) NOT NULL,

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

    description TEXT,

    image TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_station_user
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL

);