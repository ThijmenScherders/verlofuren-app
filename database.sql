CREATE DATABASE IF NOT EXISTS verlofuren CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE verlofuren;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  allowance_hours DECIMAL(8,2) NOT NULL DEFAULT 200.00,
  monday_hours DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  tuesday_hours DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  wednesday_hours DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  thursday_hours DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  friday_hours DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  saturday_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  sunday_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  remember_token_hash CHAR(64) NULL,
  remember_expires DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  hours DECIMAL(8,2) NOT NULL,
  calculation_mode ENUM('automatic','manual') NOT NULL DEFAULT 'automatic',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_leave_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_leave_dates (start_date, end_date),
  INDEX idx_leave_user (user_id)
) ENGINE=InnoDB;
