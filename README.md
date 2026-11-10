# GlowWell Beauty Clinic - Booking & Management System

PHP / MySQL / JavaScript / CSS

## Requirements
- XAMPP (or any Apache + PHP 7.4+ + MySQL 8 / MariaDB 10.4+ stack)

## Installation
1. Copy the `glowwell` folder into windows: `C:\xampp\htdocs\` linux: `/var/www/html`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open http://localhost/phpmyadmin, click **Import**, choose `database/glowwell_schema.sql` and click **Go**.
   This creates the `glowwell` database, all tables and the sample data.
4. If your MySQL user/password differ from root / (empty), edit `config/db.php`.
   If you used a different folder name than `glowwell`, change `BASE_URL` there too.
5. Browse to http://localhost/glowwell/


## Features
- Customer: register, log in, browse services, book (live staff + time-slot lookup), reschedule, cancel
- Staff: view own appointments and hours, update appointment status
- Admin: dashboard + revenue chart, full CRUD for appointments, customers, staff (services + weekly hours), services and categories, reports with CSV export and print
- Security: PDO prepared statements, password hashing, CSRF tokens, output escaping, role-based access, login lock-out
- Double-booking prevention: overlap check in a transaction plus a database UNIQUE constraint
- Email notifications (confirmation / reschedule / cancellation) - logged in the `notifications` table
  (actual delivery needs a configured mail server; the attempt is always logged)