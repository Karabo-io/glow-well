<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['staff']);

    $pdo = db();
    $staffId = staff_id_for_user($pdo, (int)$_SESSION['user_id']);
    $today = date('Y-m-d');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'status') {
        csrf_check();

        $id = (int)post('appointment_id');
        $new = post('status');
        $method = post('method');

        $st = $pdo->prepare('SELECT status, appointment_date, price_charged FROM appointments WHERE appointment_id = ? AND staff_id = ?');
        $st->execute([$id, $staffId]);
        $appt = $st->fetch();

        if (!$appt) {
            flash('error', 'That appointment could not be found.');
        } elseif (!in_array($new, ['confirmed', 'completed', 'no_show'], true)) {
            flash('error', 'Invalid status.');
        } elseif ($new === 'confirmed' && $appt['status'] !== 'pending') {
            flash('error', 'Only pending appointments can be confirmed.');
        } elseif (!in_array($appt['status'], ['pending', 'confirmed'], true)) {
            flash('error', 'This appointment is already closed and can only be changed by an administrator.');
        } elseif ($new !== 'confirmed' && $appt['appointment_date'] > $today) {
            flash('error', 'An appointment cannot be completed or marked as a no-show before its day.');
        } elseif ($new === 'completed' && !in_array($method, ['cash', 'card', 'eft'], true)) {
            flash('error', 'Please choose how the customer paid.');
        } else {
            try {
                $pdo->beginTransaction();
                $up = $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ? AND staff_id = ? AND status IN ('pending','confirmed')");
                $up->execute([$new, $id, $staffId]);
                if ($up->rowCount() === 0) {
                    $pdo->rollBack();
                    flash('error', 'That appointment was just changed by someone else. Please refresh.');
                } else {
                    if ($new === 'completed') {
                        $pdo->prepare('INSERT INTO payments (appointment_id, amount, method) VALUES (?, ?, ?)')
                            ->execute([$id, $appt['price_charged'], $method]);
                    }
                    $pdo->commit();
                    flash('success', 'Appointment updated.');
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('staff status: ' . $ex->getMessage());
                flash('error', 'Could not update the appointment. Please try again.');
            }
        }
        redirect('staff/dashboard.php');
    }

    // Upcoming 14 days, plus any past booking that still needs a final status
    $st = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status, a.notes,
                sv.service_name, CONCAT(c.first_name, ' ', c.last_name) AS customer, c.phone
        FROM appointments a
        JOIN services sv ON sv.service_id = a.service_id
        JOIN customers c ON c.customer_id = a.customer_id
        WHERE a.staff_id = ? AND a.status <> 'cancelled'
          AND ((a.appointment_date BETWEEN ? AND ?)
               OR (a.appointment_date < ? AND a.status IN ('pending','confirmed')))
        ORDER BY a.appointment_date, a.start_time"
    );
    $st->execute([$staffId, $today, date('Y-m-d', strtotime('+14 days')), $today]);
    $appts = $st->fetchAll();

    $st = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM staff_schedules WHERE staff_id = ? ORDER BY day_of_week');
    $st->execute([$staffId]);
    $hours = $st->fetchAll();

    $page_title = 'My schedule';
    require __DIR__ . '/../includes/header.php';
?>

<h1>My schedule</h1>
<div class="grid" style="grid-template-columns: 3fr 1fr; align-items:start">
    <div class="card">
        <h2>Next 14 days</h2>
        <?php if (!$appts): ?>
            <p class="muted">You have no appointments in the next two weeks.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Time</th><th>Customer</th><th>Service</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
            <?php foreach ($appts as $a): ?>
                <tr>
                    <td><?= e(fmt_date($a['appointment_date'])) ?></td>
                    <td><?= e(fmt_time($a['start_time'])) ?> - <?= e(fmt_time($a['end_time'])) ?></td>
                    <td><?= e($a['customer']) ?><br><span class="muted"><?= e($a['phone']) ?></span></td>
                    <td><?= e($a['service_name']) ?><?php if ($a['notes']): ?><br><span class="muted">Note: <?= e($a['notes']) ?></span><?php endif; ?></td>
                    <td><?= status_badge($a['status']) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="appointment_id" value="<?= (int)$a['appointment_id'] ?>">
                            <select name="status" onchange="this.form.submit()" aria-label="Update status" style="width:auto">
                                <option value="">Change...</option>
                                <option value="confirmed">Confirm</option>
                                <option value="completed">Completed</option>
                                <option value="no_show">No show</option>
                            </select>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>My working hours</h3>
        <?php if (!$hours): ?><p class="muted">No hours set yet. Please contact the administrator.</p><?php endif; ?>
        <?php foreach ($hours as $h): ?>
            <div><strong><?= e(day_name((int)$h['day_of_week'])) ?></strong><br><span class="muted"><?= e(fmt_time($h['start_time'])) ?> - <?= e(fmt_time($h['end_time'])) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>