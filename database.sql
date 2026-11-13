
CREATE DATABASE IF NOT EXISTS verlofuren
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE verlofuren;

-- ============================================================
-- GEBRUIKERS
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
    work_number VARCHAR(50) NULL,
    allowance_hours DECIMAL(8,2) NOT NULL DEFAULT 193.75,
    monday_hours DECIMAL(5,2) NOT NULL DEFAULT 7.75,
    tuesday_hours DECIMAL(5,2) NOT NULL DEFAULT 7.75,
    wednesday_hours DECIMAL(5,2) NOT NULL DEFAULT 7.75,
    thursday_hours DECIMAL(5,2) NOT NULL DEFAULT 7.75,
    friday_hours DECIMAL(5,2) NOT NULL DEFAULT 7.75,
    saturday_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    sunday_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    remember_token_hash CHAR(64) NULL,
    remember_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- DEFINITIEVE KALENDERBOEKINGEN
-- ============================================================

CREATE TABLE IF NOT EXISTS leave_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    hours DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    calculation_mode ENUM('automatic','manual') NOT NULL DEFAULT 'automatic',
    booking_type VARCHAR(30) NOT NULL DEFAULT 'leave',
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_leave_dates (start_date, end_date),
    INDEX idx_leave_user (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- AANVRAGEN
-- ============================================================

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    hours DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    calculation_mode ENUM('automatic','manual') NOT NULL DEFAULT 'automatic',
    booking_type VARCHAR(30) NOT NULL DEFAULT 'leave',
    note VARCHAR(255) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    entry_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_requests_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_requests_entry
        FOREIGN KEY (entry_id) REFERENCES leave_entries(id) ON DELETE SET NULL,
    CONSTRAINT fk_leave_requests_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_leave_requests_user (user_id),
    INDEX idx_leave_requests_status (status),
    INDEX idx_leave_requests_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TIJD-VOOR-TIJD
-- ============================================================

CREATE TABLE IF NOT EXISTS time_credits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    minutes INT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_time_credits_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_time_credits_user_date (user_id, work_date)
) ENGINE=InnoDB;

-- ============================================================
-- UITBETALINGEN
-- ============================================================

CREATE TABLE IF NOT EXISTS leave_payouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    payout_date DATE NOT NULL,
    hours DECIMAL(8,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_payouts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_leave_payouts_user_date (user_id, payout_date)
) ENGINE=InnoDB;

-- ============================================================
-- MELDINGEN
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NULL,
    notification_key VARCHAR(190) NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_notifications_user_key (user_id, notification_key),
    INDEX idx_notifications_user_read (user_id, read_at),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB;
