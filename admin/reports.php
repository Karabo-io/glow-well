<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);
    $pdo = db();

    $from = (string)($_GET['from'] ?? date('Y-m-01', strtotime('-2 months')));
    $to   = (string)($_GET['to'] ?? date('Y-m-t'));
    $error = '';
    if (!valid_date($from) || !valid_date($to)) {
        $error = 'Please enter valid dates.';
        $from = date('Y-m-01', strtotime('-2 months'));
        $to   = date('Y-m-t');
    } elseif ($from > $to) {
        $error = 'The start date must be before the end date.';
        [$from, $to] = [$to, $from];
    }
    $range = [$from, $to];

    // ---- CSV export (before any HTML output) ----
    if (($_GET['export'] ?? '') === 'appointments') {
        $st = $pdo->prepare(
            "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer, sv.service_name,
                    CONCAT(s.first_name, ' ', s.last_name) AS staff, a.status, a.price_charged,
                    COALESCE(p.amount, 0) AS amount_paid, COALESCE(p.method, '') AS pay_method
            FROM appointments a
            JOIN customers c ON c.customer_id = a.customer_id
            JOIN services sv ON sv.service_id = a.service_id
            JOIN staff s ON s.staff_id = a.staff_id
            LEFT JOIN payments p ON p.appointment_id = a.appointment_id
            WHERE a.appointment_date BETWEEN ? AND ?
            ORDER BY a.appointment_date, a.start_time"
        );
        $st->execute($range);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="glowwell_appointments_' . $from . '_to_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Date', 'Start', 'End', 'Customer', 'Service', 'Therapist', 'Status', 'Price', 'Paid', 'Method']);
        foreach ($st->fetchAll() as $r) { fputcsv($out, array_values($r)); }
        fclose($out);
        exit;
    }

    $q = function (string $sql) use ($pdo, $range) {
        $st = $pdo->prepare($sql);
        $st->execute($range);
        return $st->fetchAll();
    };

    $summary = $q(
        "SELECT COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'cancelled') AS cancelled,
                SUM(status = 'no_show') AS no_shows
        FROM appointments WHERE appointment_date BETWEEN ? AND ?"
    )[0];
    $revenue = (float)($q(
        'SELECT COALESCE(SUM(p.amount), 0) AS r FROM payments p JOIN appointments a ON a.appointment_id = p.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ?'
    )[0]['r']);

    $byService = $q(
        "SELECT sv.service_name, c.category_name, COUNT(a.appointment_id) AS bookings, COALESCE(SUM(p.amount), 0) AS revenue
        FROM appointments a
        JOIN services sv ON sv.service_id = a.service_id
        JOIN service_categories c ON c.category_id = sv.category_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status <> 'cancelled'
        GROUP BY sv.service_id, sv.service_name, c.category_name
        ORDER BY revenue DESC, bookings DESC"
    );
    $byStaff = $q(
        "SELECT CONCAT(s.first_name, ' ', s.last_name) AS staff_name, s.job_title,
                COUNT(a.appointment_id) AS bookings, SUM(a.status = 'completed') AS completed, COALESCE(SUM(p.amount), 0) AS revenue
        FROM appointments a
        JOIN staff s ON s.staff_id = a.staff_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status <> 'cancelled'
        GROUP BY s.staff_id, s.first_name, s.last_name, s.job_title
        ORDER BY revenue DESC"
    );
    $byMonth = $q(
        "SELECT DATE_FORMAT(a.appointment_date, '%Y-%m') AS ym, COUNT(DISTINCT a.appointment_id) AS bookings, COALESCE(SUM(p.amount), 0) AS revenue
        FROM appointments a LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status <> 'cancelled'
        GROUP BY ym ORDER BY ym"
    );
    $topCustomers = $q(
        "SELECT CONCAT(c.first_name, ' ', c.last_name) AS customer, COUNT(a.appointment_id) AS visits, COALESCE(SUM(p.amount), 0) AS spent
        FROM appointments a
        JOIN customers c ON c.customer_id = a.customer_id
        LEFT JOIN payments p ON p.appointment_id = a.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status <> 'cancelled'
        GROUP BY c.customer_id, c.first_name, c.last_name
        ORDER BY spent DESC, visits DESC LIMIT 5"
    );

    $page_title = 'Reports';
    require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Reports</h1>
    <div class="no-print btn-row" style="margin:0">
        <a class="btn btn-teal btn-small" href="<?= e(url('admin/reports.php?from=' . $from . '&to=' . $to . '&export=appointments')) ?>">Export CSV</a>
        <button class="btn btn-small btn-outline" type="button" onclick="window.print()">Print</button>
    </div>
</div>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="filter-bar card">
    <div class="form-group"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="form-group"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-small" type="submit">Run report</button>
</form>
<p class="muted">Showing <?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?>. Revenue counts recorded payments only.</p>

<div class="grid grid-4">
    <div class="card stat"><div class="num"><?= (int)$summary['total'] ?></div><div class="label">Total bookings</div></div>
    <div class="card stat"><div class="num"><?= (int)$summary['completed'] ?></div><div class="label">Completed</div></div>
    <div class="card stat"><div class="num"><?= (int)$summary['cancelled'] ?> / <?= (int)$summary['no_shows'] ?></div><div class="label">Cancelled / No-shows</div></div>
    <div class="card stat"><div class="num"><?= e(money($revenue)) ?></div><div class="label">Revenue</div></div>
</div>

<div class="card">
    <h2>Revenue by service</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Service</th><th>Category</th><th class="right">Bookings</th><th class="right">Revenue</th></tr></thead>
        <tbody>
        <?php if (!$byService): ?><tr><td colspan="4" class="muted">No data for this period.</td></tr><?php endif; ?>
        <?php foreach ($byService as $r): ?>
            <tr><td><?= e($r['service_name']) ?></td><td><?= e($r['category_name']) ?></td><td class="right"><?= (int)$r['bookings'] ?></td><td class="right"><?= e(money($r['revenue'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($byService): ?>
        <tfoot><tr><td colspan="2">Total</td><td class="right"><?= array_sum(array_column($byService, 'bookings')) ?></td><td class="right"><?= e(money(array_sum(array_column($byService, 'revenue')))) ?></td></tr></tfoot>
        <?php endif; ?>
    </table></div>
</div>

<div class="grid grid-3" style="grid-template-columns: repeat(auto-fit, minmax(380px, 1fr))">
    <div class="card">
        <h2>Staff performance</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Therapist</th><th class="right">Bookings</th><th class="right">Done</th><th class="right">Revenue</th></tr></thead>
            <tbody>
            <?php if (!$byStaff): ?><tr><td colspan="4" class="muted">No data for this period.</td></tr><?php endif; ?>
            <?php foreach ($byStaff as $r): ?>
                <tr><td><?= e($r['staff_name']) ?><br><span class="muted"><?= e($r['job_title']) ?></span></td><td class="right"><?= (int)$r['bookings'] ?></td><td class="right"><?= (int)$r['completed'] ?></td><td class="right"><?= e(money($r['revenue'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <h2>Monthly revenue</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Month</th><th class="right">Bookings</th><th class="right">Revenue</th></tr></thead>
            <tbody>
            <?php if (!$byMonth): ?><tr><td colspan="3" class="muted">No data for this period.</td></tr><?php endif; ?>
            <?php foreach ($byMonth as $r): ?>
                <tr><td><?= e(date('F Y', strtotime($r['ym'] . '-01'))) ?></td><td class="right"><?= (int)$r['bookings'] ?></td><td class="right"><?= e(money($r['revenue'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<div class="card">
    <h2>Top customers</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Customer</th><th class="right">Visits</th><th class="right">Total spent</th></tr></thead>
        <tbody>
        <?php if (!$topCustomers): ?><tr><td colspan="3" class="muted">No data for this period.</td></tr><?php endif; ?>
        <?php foreach ($topCustomers as $r): ?>
            <tr><td><?= e($r['customer']) ?></td><td class="right"><?= (int)$r['visits'] ?></td><td class="right"><?= e(money($r['spent'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>