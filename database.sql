CREATE DATABASE IF NOT EXISTS labriqo
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE labriqo;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(30) UNIQUE,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(120) NOT NULL,
    role ENUM('student', 'staff') NOT NULL DEFAULT 'student',
    is_approved TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT users_student_id_required
        CHECK (role = 'staff' OR (student_id IS NOT NULL AND CHAR_LENGTH(TRIM(student_id)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    scope_key VARCHAR(70) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start BIGINT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (scope_key, window_start),
    INDEX login_attempts_window (window_start)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    total_quantity INT NOT NULL,
    available_quantity INT NOT NULL,
    damaged_quantity INT NOT NULL DEFAULT 0,
    CONSTRAINT equipment_damaged_range CHECK (damaged_quantity >= 0 AND damaged_quantity <= total_quantity - available_quantity),
    location VARCHAR(120) NOT NULL,
    image_url VARCHAR(2048),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_under_maintenance TINYINT(1) NOT NULL DEFAULT 0,
    tracks_assets TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT equipment_maintenance_value CHECK (is_under_maintenance IN (0, 1)),
    CONSTRAINT equipment_category_fk FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT equipment_stock_range
        CHECK (total_quantity >= 0 AND available_quantity BETWEEN 0 AND total_quantity),
    CONSTRAINT equipment_active_value CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS borrow_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    equipment_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    reason TEXT NOT NULL,
    rejection_reason VARCHAR(1000) DEFAULT NULL,
    preferred_date DATE NOT NULL,
    request_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approval_date DATE,
    issue_date DATE,
    issue_condition VARCHAR(1000) DEFAULT NULL,
    return_condition VARCHAR(1000) DEFAULT NULL,
    settled_quantity INT NOT NULL DEFAULT 0,
    CONSTRAINT loan_settled_quantity CHECK (settled_quantity BETWEEN 0 AND quantity),
    due_date DATE,
    return_date DATE,
    extension_due_date DATE DEFAULT NULL,
    extension_reason VARCHAR(1000) DEFAULT NULL,
    extension_status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT NULL,
    extension_version INT UNSIGNED NOT NULL DEFAULT 0,
    extension_requested_at DATETIME DEFAULT NULL,
    extension_decided_at DATETIME DEFAULT NULL,
    extension_review_note VARCHAR(1000) DEFAULT NULL,
    status ENUM('pending', 'borrowed', 'returned', 'rejected', 'cancelled', 'waitlisted', 'reserved', 'expired') NOT NULL DEFAULT 'pending',
    collection_deadline DATETIME DEFAULT NULL,
    INDEX borrow_collection_deadline (status, collection_deadline),
    INDEX borrow_equipment_queue (equipment_id, status, id),
    CONSTRAINT borrow_requests_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT borrow_requests_equipment_fk FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT borrow_requests_positive_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(80) NOT NULL UNIQUE,
    current_request_id INT UNSIGNED DEFAULT NULL,
    is_damaged TINYINT(1) NOT NULL DEFAULT 0,
    retired_at DATETIME DEFAULT NULL,
    CONSTRAINT assets_equipment_fk FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT assets_current_loan_fk FOREIGN KEY (current_request_id) REFERENCES borrow_requests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_assets (
    request_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (request_id, asset_id),
    CONSTRAINT loan_assets_request_fk FOREIGN KEY (request_id) REFERENCES borrow_requests (id),
    CONSTRAINT loan_assets_asset_fk FOREIGN KEY (asset_id) REFERENCES equipment_assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    action_name VARCHAR(80) NOT NULL,
    target_type VARCHAR(30) NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    detail VARCHAR(500) DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_settlements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    outcome ENUM('returned', 'damaged', 'missing') NOT NULL,
    condition_note VARCHAR(1000) NOT NULL,
    asset_tags TEXT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES borrow_requests (id),
    FOREIGN KEY (staff_id) REFERENCES users (id),
    CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    previous_total INT NOT NULL,
    new_total INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    adjusted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_adjustment_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT stock_adjustment_staff FOREIGN KEY (staff_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories (name) VALUES
    ('Development Boards'),
    ('Test Instruments'),
    ('Networking')
ON DUPLICATE KEY UPDATE name = categories.name;

-- Re-importing the sample data leaves existing equipment quantities unchanged.
INSERT INTO equipment (equipment_code, name, category_id, description, total_quantity, available_quantity, location)
VALUES
    ('DEV-001', 'Arduino Uno', (SELECT id FROM categories WHERE name = 'Development Boards'),
        'Microcontroller board for electronics and embedded systems labs.', 10, 10, 'Electronics Lab, Cabinet A'),
    ('DEV-002', 'Raspberry Pi 4', (SELECT id FROM categories WHERE name = 'Development Boards'),
        'Single-board computer for programming and networking projects.', 5, 5, 'Electronics Lab, Cabinet A'),
    ('TST-001', 'Digital Multimeter', (SELECT id FROM categories WHERE name = 'Test Instruments'),
        'Handheld meter for measuring voltage, current, and resistance.', 8, 8, 'Electronics Lab, Cabinet B'),
    ('TST-002', 'Digital Oscilloscope', (SELECT id FROM categories WHERE name = 'Test Instruments'),
        'Bench instrument for viewing and measuring electrical signals.', 4, 4, 'Electronics Lab, Bench 2'),
    ('NET-001', 'Cat6 Network Cable', (SELECT id FROM categories WHERE name = 'Networking'),
        'Ethernet patch cable for connecting devices in networking practicals.', 15, 15, 'Networking Lab, Drawer 1')
ON DUPLICATE KEY UPDATE equipment_code = equipment.equipment_code;
