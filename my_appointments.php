<?php
    require_once __DIR__ . '/includes/functions.php';
    require_login(['customer']);

    $pdo = db();
    $customerId = customer_id_for_user($pdo, (int)$_SESSION['user_id']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'cancel') {
        csrf_check();
        $id = (int)post('appointment_id');
        $st = $pdo->prepare('SELECT status, appointment_date FROM appointments WHERE appointment_id = ? AND customer_id = ?');
        $st->execute([$id, $customerId]);
        $appt = $st->fetch();
        if (!$appt) {
            flash('error', 'That appointment could not be found.');
        } elseif (!in_array($appt['status'], ['pending', 'confirmed'], true) || $appt['appointment_date'] < date('Y-m-d')) {
            flash('error', 'This appointment can no longer be cancelled.');
        } else {
            $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ? AND customer_id = ?")
                ->execute([$id, $customerId]);
            send_notification($pdo, $id, 'cancellation');
            flash('success', 'Your appointment has been cancelled.');
        }
        redirect('my_appointments.php');
    }

    $st = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status, a.price_charged, a.notes,
                sv.service_name, CONCAT(s.first_name, ' ', s.last_name) AS staff_name
        FROM appointments a
        JOIN services sv ON sv.service_id = a.service_id
        JOIN staff s ON s.staff_id = a.staff_id
        WHERE a.customer_id = ?
        ORDER BY a.appointment_date DESC, a.start_time DESC"
    );
    $st->execute([$customerId]);
    $all = $st->fetchAll();

    $today = date('Y-m-d');
    $upcoming = $history = [];
    foreach ($all as $a) {
        if ($a['appointment_date'] >= $today && in_array($a['status'], ['pending', 'confirmed'], true)) {
            $upcoming[] = $a;
        } else {
            $history[] = $a;
        }
    }
    $upcoming = array_reverse($upcoming);   // soonest first

    $page_title = 'My appointments';
    require __DIR__ . '/includes/header.php'

?>

<div class="page-head">
    <h1>My appointments</h1>
    <a class="btn" href="<?= e(url('book.php')) ?>">+ Book new</a>
</div>

<h2>Upcoming</h2>
<?php if (!$upcoming): ?>
    <div class="card muted">You have no upcoming appointments. <a href="<?= e(url('book.php')) ?>">Book one now</a>.</div>
<?php else: ?>
<div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Therapist</th><th>Price</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $a): ?>
        <tr>
            <td><?= e(fmt_date($a['appointment_date'])) ?></td>
            <td><?= e(fmt_time($a['start_time'])) ?> - <?= e(fmt_time($a['end_time'])) ?></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= e($a['staff_name']) ?></td>
            <td><?= e(money($a['price_charged'])) ?></td>
            <td><?= status_badge($a['status']) ?></td>
            <td class="actions">
                <a class="btn btn-small btn-outline" href="<?= e(url('reschedule.php?id=' . (int)$a['appointment_id'])) ?>">Reschedule</a>
                <form method="post" class="inline-form" data-confirm="Cancel this appointment?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="appointment_id" value="<?= (int)$a['appointment_id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Cancel</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2 style="margin-top:2rem">History</h2>
<?php if (!$history): ?>
    <div class="card muted">No past appointments yet.</div>
<?php else: ?>
<div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Therapist</th><th>Price</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($history as $a): ?>
        <tr>
            <td><?= e(fmt_date($a['appointment_date'])) ?></td>
            <td><?= e(fmt_time($a['start_time'])) ?></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= e($a['staff_name']) ?></td>
            <td><?= e(money($a['price_charged'])) ?></td>
            <td><?= status_badge($a['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>