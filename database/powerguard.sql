CREATE DATABASE powerguide;
USE powerguide;

-- OUTAGE REPORTS
CREATE TABLE outage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    location_name VARCHAR(255),
    description TEXT,
    status ENUM('unverified','verified') DEFAULT 'unverified',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
);