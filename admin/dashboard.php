<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);
    $pdo = db();
    $today = date('Y-m-d');

    $one = function (string $sql, array $params = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    };

    $todayCount   = (int)$one("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status <> 'cancelled'", [$today]);
    $pendingCount = (int)$one("SELECT COUNT(*) FROM appointments WHERE status = 'pending' AND appointment_date >= ?", [$today]);
    $customers    = (int)$one('SELECT COUNT(*) FROM customers');
    $monthStart   = date('Y-m-01');
    $monthRevenue = (float)$one(
        'SELECT COALESCE(SUM(p.amount), 0) FROM payments p
        JOIN appointments a ON a.appointment_id = p.appointment_id
        WHERE a.appointment_date >= ? AND a.appointment_date <= LAST_DAY(?)',
        [$monthStart, $monthStart]
    );

    // Revenue for the last 6 months (chart)
    $rev = $pdo->prepare(
        "SELECT DATE_FORMAT(a.appointment_date, '%Y-%m') AS ym, SUM(p.amount) AS total
        FROM payments p JOIN appointments a ON a.appointment_id = p.appointment_id
        WHERE a.appointment_date >= ?
        GROUP BY ym"
    );
    $rev->execute([date('Y-m-01', strtotime('-5 months'))]);
    $byMonth = array_column($rev->fetchAll(), 'total', 'ym');
    $chart = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-$i months", strtotime($monthStart));
        $chart[] = ['label' => date('M Y', $ts), 'value' => (float)($byMonth[date('Y-m', $ts)] ?? 0)];
    }

    // Today's bookings
    $st = $pdo->prepare(
        "SELECT a.appointment_id, a.start_time, a.status, sv.service_name,
                CONCAT(c.first_name, ' ', c.last_name) AS customer, CONCAT(s.first_name, ' ', s.last_name) AS staff_name
        FROM appointments a
        JOIN customers c ON c.customer_id = a.customer_id
        JOIN services sv ON sv.service_id = a.service_id
        JOIN staff s ON s.staff_id = a.staff_id
        WHERE a.appointment_date = ? AND a.status <> 'cancelled'
        ORDER BY a.start_time"
    );
    $st->execute([$today]);
    $todays = $st->fetchAll();

    // Next 7 days (excluding today)
    $st = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status, sv.service_name,
                CONCAT(c.first_name, ' ', c.last_name) AS customer, CONCAT(s.first_name, ' ', s.last_name) AS staff_name
        FROM appointments a
        JOIN customers c ON c.customer_id = a.customer_id
        JOIN services sv ON sv.service_id = a.service_id
        JOIN staff s ON s.staff_id = a.staff_id
        WHERE a.appointment_date > ? AND a.appointment_date <= ? AND a.status IN ('pending','confirmed')
        ORDER BY a.appointment_date, a.start_time"
    );
    $st->execute([$today, date('Y-m-d', strtotime('+7 days'))]);
    $upcoming = $st->fetchAll();

    $page_title = 'Admin dashboard';
    require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Dashboard</h1>
    <a class="btn" href="<?= e(url('admin/appointment_form.php')) ?>">+ New appointment</a>
</div>

<div class="grid grid-4">
    <div class="card stat"><div class="num"><?= $todayCount ?></div><div class="label">Bookings today</div></div>
    <div class="card stat"><div class="num"><?= $pendingCount ?></div><div class="label">Pending confirmation</div></div>
    <div class="card stat"><div class="num"><?= e(money($monthRevenue)) ?></div><div class="label">Revenue this month</div></div>
    <div class="card stat"><div class="num"><?= $customers ?></div><div class="label">Registered customers</div></div>
</div>

<div class="card">
    <h2>Revenue - last 6 months</h2>
    <div class="chart" data-chart="<?= e(json_encode($chart)) ?>"></div>
</div>

<div class="card">
    <h2>Today's appointments (<?= e(fmt_date($today)) ?>)</h2>
    <?php if (!$todays): ?>
        <p class="muted">No appointments scheduled for today.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Time</th><th>Customer</th><th>Service</th><th>Therapist</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($todays as $a): ?>
            <tr>
                <td><?= e(fmt_time($a['start_time'])) ?></td><td><?= e($a['customer']) ?></td>
                <td><?= e($a['service_name']) ?></td><td><?= e($a['staff_name']) ?></td>
                <td><?= status_badge($a['status']) ?></td>
                <td><a class="btn btn-small btn-outline" href="<?= e(url('admin/appointment_form.php?id=' . (int)$a['appointment_id'])) ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Coming up - next 7 days</h2>
    <?php if (!$upcoming): ?>
        <p class="muted">No upcoming appointments in the next 7 days.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Time</th><th>Customer</th><th>Service</th><th>Therapist</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($upcoming as $a): ?>
            <tr>
                <td><?= e(fmt_date($a['appointment_date'])) ?></td><td><?= e(fmt_time($a['start_time'])) ?></td>
                <td><?= e($a['customer']) ?></td><td><?= e($a['service_name']) ?></td>
                <td><?= e($a['staff_name']) ?></td><td><?= status_badge($a['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>