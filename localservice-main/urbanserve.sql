-- Database Creation
CREATE DATABASE IF NOT EXISTS urbanserve;
USE urbanserve;

-- ========================
-- CORE TABLES
-- ========================

-- Users Table (for all user types)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'provider', 'customer') NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    pincode VARCHAR(20) NOT NULL,
    profile_image VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_role (role),
    INDEX idx_user_email (email),
    INDEX idx_user_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service Categories Table
CREATE TABLE IF NOT EXISTS service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    icon VARCHAR(50),
    INDEX idx_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Services Table
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category_id INT NOT NULL,
    description TEXT NOT NULL,
    base_price DECIMAL(10,2) NOT NULL,
    duration_minutes INT NOT NULL,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES service_categories(id),
    INDEX idx_service_category (category_id),
    INDEX idx_service_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service Images Table
CREATE TABLE IF NOT EXISTS service_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    INDEX idx_service_id (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- USER EXTENSION TABLES
-- ========================

-- Customers Table
-- not for now

-- Providers Table
CREATE TABLE IF NOT EXISTS providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    experience VARCHAR(50) NOT NULL,
    location VARCHAR(255),
    bio TEXT,
    id_proof VARCHAR(255),
    is_verified BOOLEAN DEFAULT FALSE,
    verification_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    availability ENUM('available', 'unavailable') DEFAULT 'available',
    avg_rating DECIMAL(3,2) DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_provider_verified (is_verified),
    INDEX idx_provider_availability (availability),
    INDEX idx_verification_status (verification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Provider Services (with provider-specific pricing)
CREATE TABLE IF NOT EXISTS provider_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_id INT NOT NULL,
    service_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (provider_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_service (provider_id, service_id),
    INDEX idx_provider_service (provider_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- BOOKING SYSTEM TABLES
-- ========================

-- Bookings Table (cash payment version)
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider_id INT NOT NULL,
    service_id INT NOT NULL,
    booking_date DATETIME NOT NULL,
    address TEXT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'confirmed', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    cancellation_reason TEXT,
    payment_type ENUM('cash', 'later') NOT NULL DEFAULT 'cash',
    payment_status ENUM('pending', 'paid', 'unpaid') DEFAULT 'pending',
    admin_notes TEXT,
    customer_notes TEXT,
    provider_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (provider_id) REFERENCES users(id),
    FOREIGN KEY (service_id) REFERENCES services(id),
    INDEX idx_booking_user (user_id),
    INDEX idx_booking_provider (provider_id),
    INDEX idx_booking_status (status),
    INDEX idx_booking_date (booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reviews Table
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL UNIQUE,
    user_id INT NOT NULL,
    provider_id INT NOT NULL,
    service_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (provider_id) REFERENCES users(id),
    FOREIGN KEY (service_id) REFERENCES services(id),
    INDEX idx_review_provider (provider_id),
    INDEX idx_review_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- OPERATIONAL TABLES
-- ========================

-- Provider Availability Slots
-- not for now

-- Service Areas (where providers operate)
-- not for now

-- Notifications Table
-- not for now

-- ========================
-- SAMPLE DATA INSERTION
-- ========================

-- Insert admin user
INSERT INTO users (name, email, password, role, phone, address, city, state, pincode) VALUES 
('Admin User', 'admin@urbanserve.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '9876543210', '123 Admin Street', 'Mumbai', 'Maharashtra', '400001');

-- Insert sample service categories (ignore duplicates)
INSERT IGNORE INTO service_categories (name, description, icon) VALUES
('Cleaning', 'Home and office cleaning services', 'broom'),
('Repair', 'Appliance and home repairs', 'tools'),
('Beauty', 'Personal care and grooming', 'scissors'),
('Plumbing', 'Pipe and water system services', 'pipe'),
('Electrical', 'Wiring and electrical work', 'bolt');

-- Insert customer records for existing users
INSERT IGNORE INTO customers (user_id)
SELECT id FROM users WHERE role = 'customer';

-- Insert provider availability (Mon–Fri, 9AM–6PM)
INSERT IGNORE INTO provider_availability (provider_id, day_of_week, start_time, end_time)
SELECT u.id AS provider_id, d.day, '09:00:00', '18:00:00'
FROM (SELECT id FROM users WHERE role = 'provider') u
CROSS JOIN (SELECT 1 AS day UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d;

-- Insert service areas for providers (default Mumbai pincodes)
INSERT IGNORE INTO service_areas (provider_id, city, pincodes)
SELECT id, 'Mumbai', '400001,400002,400003,400004'
FROM users WHERE role = 'provider';

-- ========================
-- DATA MODIFICATIONS
-- ========================

-- Ensure category_id has correct type and constraint
ALTER TABLE services 
MODIFY COLUMN category_id INT NOT NULL,
ADD FOREIGN KEY (category_id) REFERENCES service_categories(id);

-- Assign correct category_id to existing services
UPDATE services s
JOIN service_categories sc ON sc.name = 'Cleaning'
SET s.category_id = sc.id
WHERE s.name LIKE '%Clean%' AND s.category_id IS NULL;

UPDATE services s
JOIN service_categories sc ON sc.name = 'Repair'
SET s.category_id = sc.id
WHERE (s.name LIKE '%AC%' OR s.name LIKE '%Repair%') AND s.category_id IS NULL;

UPDATE services s
JOIN service_categories sc ON sc.name = 'Beauty'
SET s.category_id = sc.id
WHERE s.name LIKE '%Salon%' AND s.category_id IS NULL;

UPDATE services s
JOIN service_categories sc ON sc.name = 'Plumbing'
SET s.category_id = sc.id
WHERE s.name LIKE '%Plumb%' AND s.category_id IS NULL;

UPDATE services s
JOIN service_categories sc ON sc.name = 'Electrical'
SET s.category_id = sc.id
WHERE s.name LIKE '%Electric%' AND s.category_id IS NULL;

-- Set default cancellation reason where missing
UPDATE bookings 
SET cancellation_reason = 'Customer request'
WHERE status = 'cancelled' AND cancellation_reason IS NULL;

-- Update average provider rating based on reviews
UPDATE providers p
SET avg_rating = (
    SELECT ROUND(AVG(r.rating), 2) 
    FROM reviews r 
    WHERE r.provider_id = p.user_id
);

ALTER TABLE providers 
ADD COLUMN verification_notes TEXT DEFAULT NULL 
AFTER verification_status;

-- Update booking addresses from user addresses
UPDATE bookings b
JOIN users u ON b.user_id = u.id
SET b.address = u.address;

CREATE TABLE favourites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider_id INT DEFAULT NULL,
    service_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
);

CREATE TABLE contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    subject VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);


CREATE TABLE message_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    admin_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (message_id) REFERENCES contact_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);
