-- =====================================================
-- GlowWell Beauty Clinic - Database Schema 
-- MySQL 8.0.16+ / MariaDB 10.2+
-- =====================================================

CREATE DATABASE IF NOT EXISTS glowwell CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE glowwell;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS staff_schedules;
DROP TABLE IF EXISTS staff_services;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS service_categories;
DROP TABLE IF EXISTS staff;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. users: one login account per person (hashed passwords)

CREATE TABLE users (
    user_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(150) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. customers: profile details for customer accounts
CREATE TABLE customers (
    customer_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL UNIQUE,
    first_name     VARCHAR(60) NOT NULL,
    last_name      VARCHAR(60) NOT NULL,
    phone          VARCHAR(20) NOT NULL,
    date_of_birth  DATE NULL,
    notes          VARCHAR(255) NULL,
    CONSTRAINT fk_customer_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. staff: therapists / stylists (linked to a login)
CREATE TABLE staff (
    staff_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL UNIQUE,
    first_name     VARCHAR(60) NOT NULL,
    last_name      VARCHAR(60) NOT NULL,
    phone          VARCHAR(20) NOT NULL,
    job_title      VARCHAR(80) NOT NULL,
    hire_date      DATE NOT NULL,
    CONSTRAINT fk_staff_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- 4. service_categories: facials, massages, hair, etc.

CREATE TABLE service_categories (
    category_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name  VARCHAR(80) NOT NULL UNIQUE,
    description    VARCHAR(255) NULL
) ENGINE=InnoDB;

-- 5. services: what the clinic sells
CREATE TABLE services (
    service_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id       INT UNSIGNED NOT NULL,
    service_name      VARCHAR(100) NOT NULL UNIQUE,
    description       VARCHAR(255) NULL,
    duration_minutes  SMALLINT UNSIGNED NOT NULL,
    price             DECIMAL(8,2) NOT NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT chk_price CHECK (price >= 0),
    CONSTRAINT chk_duration CHECK (duration_minutes > 0),
    CONSTRAINT fk_service_category FOREIGN KEY (category_id)
        REFERENCES service_categories(category_id)
) ENGINE=InnoDB;


-- 6. staff_services: which staff can perform which services
--    (many-to-many resolved table)
CREATE TABLE staff_services (
    staff_id    INT UNSIGNED NOT NULL,
    service_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, service_id),
    CONSTRAINT fk_ss_staff FOREIGN KEY (staff_id)
        REFERENCES staff(staff_id) ON DELETE CASCADE,
    CONSTRAINT fk_ss_service FOREIGN KEY (service_id)
        REFERENCES services(service_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- 7. staff_schedules: weekly working hours per staff member
--    day_of_week: 1 = Monday ... 7 = Sunday
CREATE TABLE staff_schedules (
    schedule_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id     INT UNSIGNED NOT NULL,
    day_of_week  TINYINT UNSIGNED NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    CONSTRAINT chk_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_times CHECK (end_time > start_time),
    CONSTRAINT uq_staff_day UNIQUE (staff_id, day_of_week),
    CONSTRAINT fk_sched_staff FOREIGN KEY (staff_id)
        REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- 8. appointments: the core booking table
--    UNIQUE (staff_id, appointment_date, start_time, active_flag)
--    stops two active bookings with the same start time for one
--    staff member, while still allowing a cancelled slot to be
--    booked again. Overlaps with different start times are
--    prevented in PHP (get_available_slots + row lock).
-- -----------------------------------------------------
CREATE TABLE appointments (
    appointment_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id       INT UNSIGNED NOT NULL,
    staff_id          INT UNSIGNED NOT NULL,
    service_id        INT UNSIGNED NOT NULL,
    appointment_date  DATE NOT NULL,
    start_time        TIME NOT NULL,
    end_time          TIME NOT NULL,
    status            ENUM('pending','confirmed','completed','cancelled','no_show')
                      NOT NULL DEFAULT 'pending',
    price_charged     DECIMAL(8,2) NOT NULL,
    notes             VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- 1 while the booking is active, NULL once cancelled (NULLs are ignored by UNIQUE)
    active_flag       TINYINT GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, 1)) STORED,
    CONSTRAINT chk_appt_times CHECK (end_time > start_time),
    CONSTRAINT chk_appt_price CHECK (price_charged >= 0),
    CONSTRAINT uq_staff_slot UNIQUE (staff_id, appointment_date, start_time, active_flag),
    CONSTRAINT fk_appt_customer FOREIGN KEY (customer_id)
        REFERENCES customers(customer_id),
    CONSTRAINT fk_appt_staff FOREIGN KEY (staff_id)
        REFERENCES staff(staff_id),
    CONSTRAINT fk_appt_service FOREIGN KEY (service_id)
        REFERENCES services(service_id)
) ENGINE=InnoDB;


-- 9. payments: payment record per appointment (for reports)
CREATE TABLE payments (
    payment_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT UNSIGNED NOT NULL UNIQUE,
    amount          DECIMAL(8,2) NOT NULL,
    method          ENUM('cash','card','eft') NOT NULL,
    paid_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_amount CHECK (amount >= 0),
    CONSTRAINT fk_pay_appt FOREIGN KEY (appointment_id)
        REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. notifications: log of emails sent
CREATE TABLE notifications (
    notification_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id   INT UNSIGNED NOT NULL,
    type             ENUM('confirmation','reminder','cancellation','rescheduled') NOT NULL,
    sent_to          VARCHAR(150) NOT NULL,
    sent_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_appt FOREIGN KEY (appointment_id)
        REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(150) NOT NULL,
    ip            VARCHAR(45) NOT NULL,
    attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt_email (email, attempted_at),
    INDEX idx_attempt_ip (ip, attempted_at)
) ENGINE=InnoDB;

-- Helpful indexes for dashboards and reports
CREATE INDEX idx_appt_date   ON appointments (appointment_date);
CREATE INDEX idx_appt_status ON appointments (status);


-- ADMIN ACCOUNT
INSERT INTO users (email, password_hash, role) VALUES
('admin@glowwell.co.za', '$2y$12$RgzWJ5NC69HwZTEoRcX26uglGicS158m6uyhSZc1kEiSUeh5KcOum', 'admin');

-- =====================================================
-- SAMPLE DATA (demo staff and customers)
-- =====================================================
INSERT INTO users (email, password_hash, role) VALUES
('thandi@glowwell.co.za', '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'staff'),
('sipho@glowwell.co.za',  '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'staff'),
('lerato@glowwell.co.za', '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'staff'),
('anele@example.com',     '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'customer'),
('zanele@example.com',    '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'customer'),
('pieter@example.com',    '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'customer'),
('naledi@example.com',    '$2y$12$oasSJOuRRVrYtyRatweozObVzNeP4qqLPSc8P3lWt.TLyp79IwYqK', 'customer');

-- user_id 1 = admin, 2-4 = staff, 5-8 = customers
INSERT INTO staff (user_id, first_name, last_name, phone, job_title, hire_date) VALUES
(2, 'Thandi', 'Mokoena',  '0821234567', 'Senior Facialist',   '2022-03-01'),
(3, 'Sipho',  'Dlamini',  '0837654321', 'Massage Therapist',  '2023-06-15'),
(4, 'Lerato', 'Naidoo',   '0845551234', 'Hair Stylist',       '2024-01-10');

INSERT INTO customers (user_id, first_name, last_name, phone, date_of_birth) VALUES
(5, 'Anele',  'Khumalo',  '0711112222', '1995-04-12'),
(6, 'Zanele', 'Nkosi',    '0723334444', '1990-09-30'),
(7, 'Pieter', 'van Wyk',  '0765556666', '1988-01-21'),
(8, 'Naledi', 'Sithole',  '0797778888', '2000-11-05');

INSERT INTO service_categories (category_name, description) VALUES
('Facials',    'Skin-care and rejuvenation treatments'),
('Massages',   'Relaxation and therapeutic massage'),
('Hair',       'Hair styling and treatments'),
('Wellness',   'Holistic wellness treatments'),
('Speciality', 'Speciality beauty services');

INSERT INTO services (category_id, service_name, description, duration_minutes, price) VALUES
(1, 'Classic Facial',       'Deep cleanse, exfoliation and mask',  60, 450.00),
(1, 'Anti-Ageing Facial',   'Targeted anti-ageing treatment',      75, 650.00),
(2, 'Swedish Massage',      'Full body relaxation massage',        60, 500.00),
(2, 'Deep Tissue Massage',  'Therapeutic muscle-release massage',  60, 600.00),
(3, 'Hair Treatment',       'Deep conditioning and scalp therapy', 45, 350.00),
(3, 'Cut and Style',        'Wash, cut and blow-dry',              60, 400.00),
(4, 'Aromatherapy Session', 'Essential-oil wellness session',      45, 380.00),
(5, 'Gel Manicure',         'Gel nails with shaping and polish',   60, 320.00);

INSERT INTO staff_services (staff_id, service_id) VALUES
(1,1),(1,2),(1,7),(1,8),
(2,3),(2,4),(2,7),
(3,5),(3,6),(3,8);

-- Working hours (1 = Monday ... 7 = Sunday)
INSERT INTO staff_schedules (staff_id, day_of_week, start_time, end_time) VALUES
(1,1,'08:00','16:00'),(1,2,'08:00','16:00'),(1,3,'08:00','16:00'),(1,4,'08:00','16:00'),(1,5,'08:00','16:00'),
(2,1,'09:00','17:00'),(2,2,'09:00','17:00'),(2,3,'09:00','17:00'),(2,4,'09:00','17:00'),(2,6,'09:00','13:00'),
(3,2,'08:00','16:00'),(3,3,'08:00','16:00'),(3,4,'08:00','16:00'),(3,5,'08:00','16:00'),(3,6,'08:00','14:00');

-- Past appointments (appointment_id 1-17). Everything dated before
-- 9 Nov 2026 is completed, cancelled or no_show, so no past booking
-- is left as pending or confirmed.
INSERT INTO appointments
(customer_id, staff_id, service_id, appointment_date, start_time, end_time, status, price_charged) VALUES
(1, 1, 1, '2026-11-02', '09:00', '10:00', 'completed', 450.00),
(2, 2, 3, '2026-11-02', '10:00', '11:00', 'completed', 500.00),
(3, 2, 4, '2026-11-03', '14:00', '15:00', 'completed', 600.00),
(4, 3, 6, '2026-11-04', '09:00', '10:00', 'completed', 400.00),
(1, 1, 2, '2026-11-05', '11:00', '12:15', 'completed', 650.00),
(2, 3, 5, '2026-11-05', '13:00', '13:45', 'cancelled', 350.00),
(4, 1, 7, '2026-11-06', '10:00', '10:45', 'completed', 380.00),
(2, 1, 1, '2026-09-14', '10:00', '11:00', 'completed', 450.00),
(1, 3, 5, '2026-09-23', '09:00', '09:45', 'completed', 350.00),
(1, 1, 1, '2026-10-05', '09:00', '10:00', 'completed', 450.00),
(2, 2, 3, '2026-10-06', '10:00', '11:00', 'completed', 500.00),
(3, 3, 6, '2026-10-07', '11:00', '12:00', 'completed', 400.00),
(4, 1, 2, '2026-10-12', '13:00', '14:15', 'completed', 650.00),
(1, 2, 4, '2026-10-14', '09:00', '10:00', 'completed', 600.00),
(2, 3, 8, '2026-10-16', '10:00', '11:00', 'completed', 320.00),
(3, 1, 7, '2026-10-20', '09:00', '09:45', 'completed', 380.00),
(4, 2, 3, '2026-10-22', '15:00', '16:00', 'no_show',   500.00);

-- Upcoming appointments (appointment_id 18-20), dated relative to the
-- day you import this script: next Monday, Tuesday and Wednesday.
-- Each falls on a day that staff member actually works.
SET @next_monday = CURDATE() + INTERVAL (7 - WEEKDAY(CURDATE())) DAY;

INSERT INTO appointments
(customer_id, staff_id, service_id, appointment_date, start_time, end_time, status, price_charged) VALUES
(3, 2, 4, @next_monday,                     '10:00', '11:00', 'confirmed', 600.00),
(1, 1, 1, @next_monday + INTERVAL 1 DAY,    '09:00', '10:00', 'pending',   450.00),
(2, 3, 6, @next_monday + INTERVAL 2 DAY,    '09:00', '10:00', 'confirmed', 400.00);

INSERT INTO payments (appointment_id, amount, method, paid_at) VALUES
(1,  450.00, 'card', '2026-11-02 10:05:00'),
(2,  500.00, 'cash', '2026-11-02 11:05:00'),
(3,  600.00, 'card', '2026-11-03 15:05:00'),
(4,  400.00, 'eft',  '2026-11-04 10:05:00'),
(5,  650.00, 'card', '2026-11-05 12:20:00'),
(7,  380.00, 'cash', '2026-11-06 10:50:00'),
(8,  450.00, 'card', '2026-09-14 11:05:00'),
(9,  350.00, 'eft',  '2026-09-23 09:50:00'),
(10, 450.00, 'card', '2026-10-05 10:05:00'),
(11, 500.00, 'cash', '2026-10-06 11:05:00'),
(12, 400.00, 'card', '2026-10-07 12:05:00'),
(13, 650.00, 'card', '2026-10-12 14:20:00'),
(14, 600.00, 'eft',  '2026-10-14 10:05:00'),
(15, 320.00, 'cash', '2026-10-16 11:05:00'),
(16, 380.00, 'card', '2026-10-20 09:50:00');

INSERT INTO notifications (appointment_id, type, sent_to) VALUES
(1,  'confirmation', 'anele@example.com'),
(3,  'confirmation', 'pieter@example.com'),
(6,  'cancellation', 'zanele@example.com'),
(18, 'confirmation', 'pieter@example.com');