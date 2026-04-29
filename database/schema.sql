-- ============================================================
-- LifeLink Blood Donation Platform — Database Schema
-- Target database : mobileapps_2026B_deubaybe_dounia
-- Author          : Group 3 — CS415, Ashesi University 2026
-- ============================================================
-- IMPORTANT — Case-sensitive server note:
--   The shared server already has a lowercase 'users' table used
--   by another project.  LifeLink's user table is named 'Users'
--   (capital U) to avoid collision.  All other LifeLink table
--   names are unchanged because they do not conflict with any
--   existing table on the server.
-- ============================================================

USE `mobileapps_2026B_deubaybe_dounia`;

-- ============================================================
-- TABLE 1 — Users
-- Renamed from 'users' to 'Users' (capital U) to avoid
-- collision with the existing lowercase 'users' table on the
-- shared server.  MySQL on Linux is case-sensitive by default.
-- ============================================================
CREATE TABLE IF NOT EXISTS Users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    full_name    VARCHAR(150)  NOT NULL,
    email        VARCHAR(191)  NOT NULL UNIQUE,
    phone        VARCHAR(30),
    password_hash VARCHAR(255) NOT NULL,
    role         ENUM('donor','hospital','health_worker','admin') NOT NULL DEFAULT 'donor',
    profile_photo VARCHAR(255) DEFAULT NULL,
    location     VARCHAR(255),
    city         VARCHAR(100),
    region       VARCHAR(100),
    latitude     DECIMAL(10,8),
    longitude    DECIMAL(11,8),
    is_active    TINYINT(1)   DEFAULT 1,
    is_verified  TINYINT(1)   DEFAULT 0,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 2 — donor_profiles
-- No name conflict — does not exist on the server.
-- ============================================================
CREATE TABLE IF NOT EXISTS donor_profiles (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT NOT NULL UNIQUE,
    blood_type              ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','unknown') DEFAULT 'unknown',
    blood_type_verified     TINYINT(1)    DEFAULT 0,
    verified_by             INT           DEFAULT NULL,
    verified_at             TIMESTAMP     NULL,
    date_of_birth           DATE,
    gender                  ENUM('male','female','other'),
    weight_kg               DECIMAL(5,2),
    is_eligible             TINYINT(1)    DEFAULT 1,
    availability_status     ENUM('available','unavailable','cooldown') DEFAULT 'available',
    last_donation_date      DATE,
    total_donations         INT           DEFAULT 0,
    emergency_contact_name  VARCHAR(150),
    emergency_contact_phone VARCHAR(30),
    medical_conditions      TEXT,
    FOREIGN KEY (user_id)    REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES Users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 3 — hospitals
-- No name conflict — does not exist on the server.
-- ============================================================
CREATE TABLE IF NOT EXISTS hospitals (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL UNIQUE,
    hospital_name       VARCHAR(255) NOT NULL,
    registration_number VARCHAR(100),
    hospital_type       ENUM('public','private','clinic','mobile') DEFAULT 'public',
    address             TEXT,
    city                VARCHAR(100),
    region              VARCHAR(100),
    latitude            DECIMAL(10,8),
    longitude           DECIMAL(11,8),
    is_approved         TINYINT(1)   DEFAULT 0,
    approved_by         INT          DEFAULT NULL,
    approved_at         TIMESTAMP    NULL,
    FOREIGN KEY (user_id)     REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES Users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 4 — blood_requests
-- No name conflict — does not exist on the server.
-- ============================================================
CREATE TABLE IF NOT EXISTS blood_requests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id  INT NOT NULL,
    requested_by INT NOT NULL,
    patient_name VARCHAR(150),
    blood_type   ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_needed INT  NOT NULL DEFAULT 1,
    urgency      ENUM('critical','urgent','standard') NOT NULL DEFAULT 'standard',
    reason       TEXT,
    ward         VARCHAR(100),
    location     VARCHAR(255),
    latitude     DECIMAL(10,8),
    longitude    DECIMAL(11,8),
    status       ENUM('open','matched','in_progress','fulfilled','cancelled') DEFAULT 'open',
    fulfilled_at TIMESTAMP NULL,
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id)  REFERENCES hospitals(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES Users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 5 — donor_matches
-- No name conflict — does not exist on the server.
-- ============================================================
CREATE TABLE IF NOT EXISTS donor_matches (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT NOT NULL,
    donor_id     INT NOT NULL,
    distance_km  DECIMAL(10,2),
    match_score  DECIMAL(5,2),
    status       ENUM('notified','accepted','declined','completed','cancelled') DEFAULT 'notified',
    notified_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    notes        TEXT,
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id)   REFERENCES Users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 6 — notifications
-- No name conflict — server has 'app_notifications', not 'notifications'.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    user_id            INT NOT NULL,
    type               ENUM('emergency_request','match_found','request_fulfilled','verification','system','reminder') NOT NULL,
    title              VARCHAR(255) NOT NULL,
    message            TEXT         NOT NULL,
    related_request_id INT          DEFAULT NULL,
    is_read            TINYINT(1)   DEFAULT 0,
    created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)            REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_request_id) REFERENCES blood_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 7 — donation_records
-- No name conflict — does not exist on the server.
-- ============================================================
CREATE TABLE IF NOT EXISTS donation_records (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    donor_id      INT  NOT NULL,
    match_id      INT  DEFAULT NULL,
    hospital_id   INT,
    blood_type    ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-'),
    units_donated DECIMAL(4,2) DEFAULT 1.0,
    donation_date DATE NOT NULL,
    verified_by   INT  DEFAULT NULL,
    notes         TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id)   REFERENCES Users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES Users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE 8 — blood_compatibility  (static reference, no FKs)
-- No name conflict — does not exist on the server.
-- ============================================================
CREATE TABLE IF NOT EXISTS blood_compatibility (
    donor_type     VARCHAR(5) NOT NULL,
    recipient_type VARCHAR(5) NOT NULL,
    PRIMARY KEY (donor_type, recipient_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED — Blood compatibility rules (29 valid pairs)
-- ============================================================
INSERT IGNORE INTO blood_compatibility (donor_type, recipient_type) VALUES
('O-','O-'),('O-','O+'),('O-','A-'),('O-','A+'),
('O-','B-'),('O-','B+'),('O-','AB-'),('O-','AB+'),
('O+','O+'),('O+','A+'),('O+','B+'),('O+','AB+'),
('A-','A-'),('A-','A+'),('A-','AB-'),('A-','AB+'),
('A+','A+'),('A+','AB+'),
('B-','B-'),('B-','B+'),('B-','AB-'),('B-','AB+'),
('B+','B+'),('B+','AB+'),
('AB-','AB-'),('AB-','AB+'),
('AB+','AB+');

-- ============================================================
-- SEED — Sample accounts  (password for all: "password")
-- Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- ============================================================
INSERT IGNORE INTO Users
    (full_name, email, phone, password_hash, role, location, city, region, latitude, longitude, is_active, is_verified)
VALUES
('Admin User',      'admin@lifelink.gh',    '+233201234567', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',        'Accra, Ghana',      'Accra',  'Greater Accra',  5.6037,  -0.1870, 1, 1),
('Kwame Asante',    'kwame@lifelink.gh',    '+233209876543', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor',        'East Legon, Accra', 'Accra',  'Greater Accra',  5.6411,  -0.1575, 1, 1),
('Ama Serwaa',      'ama@lifelink.gh',      '+233241122334', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor',        'Tema, Ghana',       'Tema',   'Greater Accra',  5.6698,  -0.0166, 1, 1),
('KATH Hospital',   'kath@lifelink.gh',     '+233322022301', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hospital',     'Kumasi, Ghana',     'Kumasi', 'Ashanti',        6.6885,  -1.6244, 1, 1),
('Dr. Abena Mensah','abena@lifelink.gh',    '+233261234567', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'health_worker','Accra, Ghana',      'Accra',  'Greater Accra',  5.6037,  -0.1870, 1, 1),
('Kofi Boateng',    'kofi@lifelink.gh',     '+233557891234', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor',        'Adabraka, Accra',   'Accra',  'Greater Accra',  5.5632,  -0.2107, 1, 0),
('Efua Darko',      'efua@lifelink.gh',     '+233267891234', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'donor',        'Legon, Accra',      'Accra',  'Greater Accra',  5.6496,  -0.1869, 1, 1),
('Korle Bu Hospital','korlebu@lifelink.gh', '+233302674201', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hospital',     'Korle Bu, Accra',   'Accra',  'Greater Accra',  5.5464,  -0.2267, 1, 1);

INSERT IGNORE INTO donor_profiles
    (user_id, blood_type, blood_type_verified, verified_by, date_of_birth, gender, weight_kg, is_eligible, availability_status, last_donation_date, total_donations)
VALUES
(1, 'O+',  1, 1, '1995-03-15', 'male',   72.5, 1, 'available', '2024-10-10', 5),
(2, 'A-',  1, 5, '1998-07-22', 'female', 58.0, 1, 'available', NULL,         2),
(6, 'B+',  0, NULL,'1993-11-08','male',  80.0, 1, 'available', '2025-01-15', 3),
(7, 'AB+', 1, 5, '2000-05-30', 'female', 62.0, 1, 'cooldown',  '2025-02-20', 1);

INSERT IGNORE INTO hospitals
    (user_id, hospital_name, registration_number, hospital_type, address, city, region, latitude, longitude, is_approved, approved_by)
VALUES
(4, 'Komfo Anokye Teaching Hospital', 'GH-HOS-001', 'public', 'Bantama, Kumasi',       'Kumasi', 'Ashanti',       6.6885, -1.6244, 1, 1),
(8, 'Korle Bu Teaching Hospital',     'GH-HOS-002', 'public', 'Guggisberg Ave, Accra', 'Accra',  'Greater Accra', 5.5464, -0.2267, 1, 1);

INSERT IGNORE INTO blood_requests
    (hospital_id, requested_by, patient_name, blood_type, units_needed, urgency, reason, location, latitude, longitude, status)
VALUES
(1, 4, 'Patient A', 'O-',  2, 'critical', 'Emergency surgery - road accident', 'KATH, Kumasi',    6.6885, -1.6244, 'open'),
(2, 8, 'Patient B', 'B+',  1, 'urgent',   'Obstetric emergency',               'Korle Bu, Accra', 5.5464, -0.2267, 'matched'),
(1, 4, 'Patient C', 'A+',  3, 'standard', 'Scheduled surgery',                 'KATH, Kumasi',    6.6885, -1.6244, 'fulfilled'),
(2, 8, 'Patient D', 'AB-', 1, 'urgent',   'Severe anemia',                     'Korle Bu, Accra', 5.5464, -0.2267, 'open');

INSERT IGNORE INTO notifications
    (user_id, type, title, message, related_request_id, is_read)
VALUES
(2, 'emergency_request', 'Emergency Blood Needed',    'O+ blood urgently needed at Korle Bu Teaching Hospital. You are 3.2km away.', 2, 0),
(2, 'match_found',       'Match Confirmed',           'Your blood donation has been matched with a patient at KATH. Please report within 2 hours.', 1, 1),
(3, 'verification',      'Blood Type Verified',       'Your blood type A- has been verified by Dr. Abena Mensah.', NULL, 0),
(6, 'reminder',          'Donation Eligibility Restored','It has been 90 days since your last donation. You are now eligible to donate again!', NULL, 0);

INSERT IGNORE INTO donation_records
    (donor_id, hospital_id, blood_type, units_donated, donation_date, verified_by)
VALUES
(2, 1, 'O+', 1.0, '2024-10-10', 5),
(2, 2, 'O+', 1.0, '2024-07-04', 5),
(3, 2, 'A-', 1.0, '2024-09-15', 5),
(6, 1, 'B+', 1.0, '2025-01-15', 5);
