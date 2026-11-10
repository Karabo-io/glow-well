# GlowWell Beauty Clinic - Booking & Management System

PHP / MySQL / JavaScript / CSS


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

  ## Requirements

GlowWell is a PHP + MySQL/MariaDB web application. It was developed on Apache2 and
also ships with a Docker setup.

| Component | Requirement |
|-----------|-------------|
| PHP | 8.1 or newer (the Docker image uses 8.3) |
| PHP extension `pdo_mysql` | **Required.** All database access goes through PDO. |
| PHP extension `mbstring` | **Required.** Form validation uses `mb_strlen()` for names, notes and job titles. |
| PHP extension `mysqli` | Optional. Only the connection test script uses it. |
| Web server | Apache 2.4 (with PHP enabled) |
| Database | MariaDB 10.2+ or MySQL 8.0.16+ (the schema uses CHECK constraints and generated columns) |
| Mail server | Optional. Without one, emails are not delivered, but they are still logged in the `notifications` table. |

Without `pdo_mysql` the site fails with *"could not find driver"*. Without `mbstring`
the Register page crashes with *"Call to undefined function mb_strlen()"*.

### Install the requirements

**Parrot OS / Debian / Ubuntu**

```bash
sudo apt install apache2 php libapache2-mod-php php-mysql php-mbstring mariadb-server
sudo systemctl restart apache2
```

**Windows (XAMPP)**: open `php.ini` and remove the semicolon from the start of
`;extension=pdo_mysql` and `;extension=mbstring`, then restart Apache.

**Docker (any PC)**: nothing to install. The image installs the extensions itself:

```bash
docker compose up -d --build
```

### Check that the extensions are installed

```bash
php -m | grep -Ei "pdo_mysql|mbstring"
```

Both names should be printed. The command line and Apache can use different PHP
settings, so also confirm in the browser: create `check.php` in the project folder,
open it, and delete it afterwards.

```php
<?php
foreach (['pdo_mysql', 'mbstring'] as $ext) {
    echo $ext . ': ' . (extension_loaded($ext) ? 'OK' : 'MISSING') . '<br>';
}
```

### Troubleshooting

| Message | Cause | Fix |
|---------|-------|-----|
| `could not find driver` | `pdo_mysql` is not enabled | Install or enable it, then restart Apache |
| `Call to undefined function mb_strlen()` | `mbstring` is not enabled | Install or enable it, then restart Apache |
| Blank page or `500 Internal Server Error` | A PHP error is hidden from the page | Read the log: `sudo tail -n 20 /var/log/apache2/error.log` |
| Links lead to the wrong address | `BASE_URL` does not match the folder name | Set `BASE_URL=/glowwell` in `.env` |