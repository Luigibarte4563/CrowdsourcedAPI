CREATE DATABASE powerguide;
USE powerguide;

-- =========================
-- USERS
-- =========================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,

    email VARCHAR(150) UNIQUE NOT NULL,

    password VARCHAR(255) NOT NULL,

    role ENUM(
        'user',
        'electric_company',
        'admin'
    ) DEFAULT 'user',

    account_status ENUM(
        'active',
        'suspended',
        'banned'
    ) DEFAULT 'active',

    is_verified BOOLEAN DEFAULT FALSE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);



-- =========================
-- ELECTRIC COMPANIES
-- =========================
CREATE TABLE electric_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNIQUE,

    company_name VARCHAR(255) NOT NULL,

    company_email VARCHAR(150),

    contact_number VARCHAR(50),

    address TEXT,

    verification_status ENUM(
        'pending',
        'verified',
        'rejected'
    ) DEFAULT 'pending',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
);



-- =========================
-- OUTAGE REPORTS
-- =========================
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



-- =========================
-- MAINTENANCE SCHEDULES
-- =========================
CREATE TABLE maintenance_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,

    electric_company_id INT,

    affected_area VARCHAR(255) NOT NULL,

    maintenance_date DATE NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    description TEXT,

    status ENUM(
        'upcoming',
        'ongoing',
        'completed',
        'cancelled'
    ) DEFAULT 'upcoming',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (electric_company_id)
    REFERENCES electric_companies(id)
    ON DELETE CASCADE
);



-- =========================
-- USER APPLICATIONS
-- Users can apply to become
-- verified electric companies
-- =========================
CREATE TABLE company_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT,

    company_name VARCHAR(255),

    business_document VARCHAR(255),

    application_status ENUM(
        'pending',
        'approved',
        'rejected'
    ) DEFAULT 'pending',

    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    reviewed_at TIMESTAMP NULL,

    FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
);