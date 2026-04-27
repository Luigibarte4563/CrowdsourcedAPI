CREATE TABLE outage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    location_name VARCHAR(255),

    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),

    description TEXT,

    status ENUM('unverified','verified') DEFAULT 'unverified',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_outage_user
    FOREIGN KEY (user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
);
