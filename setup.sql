-- ============================================
-- ATTENDTRACK — DATABASE SETUP  (v3)
-- Run this in phpMyAdmin > SQL tab
-- ============================================

CREATE DATABASE IF NOT EXISTS attendance_db;
USE attendance_db;

-- Users (Teachers/Admins)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    school_id VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Sections
CREATE TABLE IF NOT EXISTS sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Students
CREATE TABLE IF NOT EXISTS students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    school_id VARCHAR(50) UNIQUE NOT NULL,
    section_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
);

-- QR Sessions (generated per section, time-limited)
CREATE TABLE IF NOT EXISTS qr_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    section_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    start_time DATETIME NOT NULL,
    present_until DATETIME NOT NULL,
    late_until DATETIME NOT NULL,
    session_end DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
);

-- Attendance Records
CREATE TABLE IF NOT EXISTS attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    status ENUM('Present','Late','Absent') DEFAULT 'Present',
    date DATE NOT NULL,
    time_in TIME NOT NULL,
    UNIQUE KEY no_duplicate (student_id, date),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES qr_sessions(id) ON DELETE CASCADE
);

-- ── NEW v3: Device Locks ───────────────────────────────────────────────────────
-- Prevents one device from being used to mark attendance for multiple students.
-- One fingerprint can only be linked to ONE student per day.
CREATE TABLE IF NOT EXISTS device_locks (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    fingerprint  VARCHAR(128) NOT NULL,
    student_id   INT NOT NULL,
    locked_date  DATE NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_device_date (fingerprint, locked_date),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── NEW v3: System Logs ───────────────────────────────────────────────────────
-- Automatic log of all significant events: logins, logouts, attendance,
-- QR generation, backups, and more. Used by the auto-backup system.
CREATE TABLE IF NOT EXISTS system_logs (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    event_type  VARCHAR(50)  NOT NULL,   -- 'login','logout','attendance','auto_backup', etc.
    description TEXT,
    user_id     INT          DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event   (event_type),
    INDEX idx_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Sample sections
INSERT IGNORE INTO sections (name) VALUES ('Info1A'), ('Info2B');
