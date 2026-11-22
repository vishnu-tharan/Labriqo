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
    location VARCHAR(120) NOT NULL,
    image_url VARCHAR(2048),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_under_maintenance TINYINT(1) NOT NULL DEFAULT 0,
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
    due_date DATE,
    return_date DATE,
    extension_due_date DATE DEFAULT NULL,
    extension_reason VARCHAR(1000) DEFAULT NULL,
    extension_status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT NULL,
    extension_version INT UNSIGNED NOT NULL DEFAULT 0,
    extension_requested_at DATETIME DEFAULT NULL,
    extension_decided_at DATETIME DEFAULT NULL,
    extension_review_note VARCHAR(1000) DEFAULT NULL,
    status ENUM('pending', 'borrowed', 'returned', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    CONSTRAINT borrow_requests_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT borrow_requests_equipment_fk FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT borrow_requests_positive_quantity CHECK (quantity > 0)
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
