CREATE DATABASE powerguide;
USE powerguide;

-- OUTAGE REPORTS
CREATE TABLE outage_reports (

    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT,

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

    severity ENUM(
        'minor',
        'moderate',
        'critical'
    ) DEFAULT 'moderate',

    description TEXT,

    status ENUM(
        'unverified',
        'under_review',
        'verified',
        'resolved'
    ) DEFAULT 'unverified',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
);