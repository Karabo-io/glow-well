<?php

    require_once __DIR__ . '/functions.php';
    $page_title = $page_title ?? 'GlowWell Beauty Clinic';
    $role = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> | GlowWell</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="<?= e(url('index.php')) ?>"><span class="brand-mark">&#10048;</span> GlowWell</a>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" type="button">&#9776;</button>
        <nav id="mainNav" class="main-nav">
            <?php if ($role === 'admin'): ?>
                <a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a>
                <a href="<?= e(url('admin/appointments.php')) ?>">Appointments</a>
                <a href="<?= e(url('admin/customers.php')) ?>">Customers</a>
                <a href="<?= e(url('admin/staff.php')) ?>">Staff</a>
                <a href="<?= e(url('admin/services.php')) ?>">Services</a>
                <a href="<?= e(url('admin/reports.php')) ?>">Reports</a>
            <?php elseif ($role === 'staff'): ?>
                <a href="<?= e(url('staff/dashboard.php')) ?>">My Schedule</a>
            <?php elseif ($role === 'customer'): ?>
                <a href="<?= e(url('index.php')) ?>">Services</a>
                <a href="<?= e(url('book.php')) ?>">Book Now</a>
                <a href="<?= e(url('my_appointments.php')) ?>">My Appointments</a>
            <?php else: ?>
                <a href="<?= e(url('index.php')) ?>">Home</a>
                <a href="<?= e(url('index.php#services')) ?>">Services</a>
                <a href="<?= e(url('login.php')) ?>">Login</a>
                <a class="btn btn-small" href="<?= e(url('register.php')) ?>">Register</a>
            <?php endif; ?>
            <?php if ($role): ?>
                <span class="nav-user"><?= e($_SESSION['name'] ?? '') ?> (<?= e($role) ?>)</span>
                <a class="btn btn-small btn-outline" href="<?= e(url('logout.php')) ?>">Logout</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
<?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
