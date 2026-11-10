<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);

    $pdo = db();
    $statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];

    function has_payment(PDO $pdo, int $appointmentId): bool {
        $st = $pdo->prepare('SELECT 1 FROM payments WHERE appointment_id = ?');
        $st->execute([$appointmentId]);
        return (bool)$st->fetchColumn();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();

        $action = post('action');
        $id = (int)post('appointment_id');

        $st = $pdo->prepare('SELECT status FROM appointments WHERE appointment_id = ?');
        $st->execute([$id]);
        $current = $st->fetchColumn();

        if ($current === false) {
            flash('error', 'That appointment does not exist.');
        } elseif ($action === 'status') {
            $new = post('status');

            if (!in_array($new, $statuses, true)) {
                flash('error', 'Invalid status selected.');
            } elseif ($new === $current) {
                // unchanged: do nothing, send nothing
            } elseif ($new === 'completed' && !has_payment($pdo, $id)) {
                flash('info', 'Please record how the customer paid to complete this appointment.');
                redirect('admin/appointment_form.php?id=' . $id . '&status=completed');
            } elseif ($new !== 'completed' && has_payment($pdo, $id)) {
                flash('error', 'This appointment has a payment on record, so its status cannot be changed.');
            } else {
                try {
                    $pdo->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?')->execute([$new, $id]);
                    flash('success', 'Appointment status updated.');
                    if ($new === 'cancelled') {
                        try { send_notification($pdo, $id, 'cancellation'); }
                        catch (Throwable $ex) { error_log('cancel notification: ' . $ex->getMessage()); }
                    }
                } catch (PDOException $ex) {
                    flash('error', $ex->getCode() === '23000'
                        ? 'Cannot restore this booking: that time slot has since been booked by someone else.'
                        : 'Could not update the status.');
                }
            }
        } elseif ($action === 'delete') {
            if (has_payment($pdo, $id)) {
                flash('error', 'This appointment has a payment on record and cannot be deleted. Cancel it instead.');
            } else {
                try {
                    $pdo->prepare('DELETE FROM appointments WHERE appointment_id = ?')->execute([$id]);
                    flash('success', 'Appointment deleted.');
                } catch (PDOException $ex) {
                    error_log('appointment delete: ' . $ex->getMessage());
                    flash('error', 'Could not delete the appointment.');
                }
            }
        }

        $back = array_filter([
            'from'     => post('f_from'),
            'to'       => post('f_to'),
            'status'   => post('f_status'),
            'staff_id' => post('f_staff'),
            'q'        => post('f_q'),
        ], fn($v) => $v !== '' && $v !== '0');
        redirect('admin/appointments.php' . ($back ? '?' . http_build_query($back) : ''));
    }

    $from    = (string)($_GET['from'] ?? '');
    $to      = (string)($_GET['to'] ?? '');
    $fStatus = (string)($_GET['status'] ?? '');
    $fStaff  = (int)($_GET['staff_id'] ?? 0);
    $q       = trim((string)($_GET['q'] ?? ''));

    $where =[];
    $params = [];
    if (valid_date($from)) {
         $where[] = 'a.appointment_date >= ?'; $params[] = $from;
    } else { 
        $from = ''; 
    }

    if (valid_date($to))   { 
        $where[] = 'a.appointment_date <= ?'; $params[] = $to; 
    } else { 
        $to = ''; 
    }

    if (in_array($fStatus, $statuses, true)) { 
        $where[] = 'a.status = ?'; $params[] = $fStatus; 
    } else { 
        $fStatus = ''; 
    }

    if ($fStaff > 0) { 
        $where[] = 'a.staff_id = ?'; $params[] = $fStaff; 
    }

    if ($q !== '') {
        $where[] = "(CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR sv.service_name LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }

    $sql = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.status, a.price_charged,
               sv.service_name, CONCAT(c.first_name, ' ', c.last_name) AS customer,
               CONCAT(s.first_name, ' ', s.last_name) AS staff_name
        FROM appointments a
        JOIN customers c ON c.customer_id = a.customer_id
        JOIN services sv ON sv.service_id = a.service_id
        JOIN staff s ON s.staff_id = a.staff_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 300';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $appointments = $st->fetchAll();
    $staffList = $pdo->query("SELECT staff_id, CONCAT(first_name, ' ', last_name) AS name FROM staff ORDER BY first_name")->fetchAll();

    $page_title = 'Appointments';
    require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h1>Appointments</h1>
    <a class="btn" href="<?= e(url('admin/appointment_form.php')) ?>">+ New appointment</a>
</div>

<form method="get" class="filter-bar card">
    <div class="form-group"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="form-group"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="form-group"><label for="status">Status</label>
        <select id="status" name="status"><option value="">All</option>
            <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
        </select></div>
    <div class="form-group"><label for="staff_id">Therapist</label>
        <select id="staff_id" name="staff_id"><option value="0">All</option>
            <?php foreach ($staffList as $s): ?><option value="<?= (int)$s['staff_id'] ?>" <?= $fStaff === (int)$s['staff_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="form-group"><label for="q">Customer / service</label><input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Search..."></div>
    <button class="btn btn-small" type="submit">Filter</button>
    <a class="btn btn-small btn-outline" href="<?= e(url('admin/appointments.php')) ?>">Clear</a>
</form>

<div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Date</th><th>Time</th><th>Customer</th><th>Service</th><th>Therapist</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$appointments): ?>
        <tr><td colspan="9" class="muted">No appointments match your filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($appointments as $a): ?>
        <tr>
            <td><?= (int)$a['appointment_id'] ?></td>
            <td><?= e(fmt_date($a['appointment_date'])) ?></td>
            <td><?= e(fmt_time($a['start_time'])) ?> - <?= e(fmt_time($a['end_time'])) ?></td>
            <td><?= e($a['customer']) ?></td>
            <td><?= e($a['service_name']) ?></td>
            <td><?= e($a['staff_name']) ?></td>
            <td><?= e(money($a['price_charged'])) ?></td>
            <td>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="appointment_id" value="<?= (int)$a['appointment_id'] ?>">
                    <input type="hidden" name="f_from" value="<?= e($from) ?>"><input type="hidden" name="f_to" value="<?= e($to) ?>">
                    <input type="hidden" name="f_status" value="<?= e($fStatus) ?>"><input type="hidden" name="f_staff" value="<?= $fStaff ?: '' ?>">
                    <input type="hidden" name="f_q" value="<?= e($q) ?>">
                    <select name="status" onchange="this.form.submit()" aria-label="Change status" style="width:auto">
                        <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td class="actions">
                <a class="btn btn-small btn-outline" href="<?= e(url('admin/appointment_form.php?id=' . (int)$a['appointment_id'])) ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Permanently delete this appointment?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="appointment_id" value="<?= (int)$a['appointment_id'] ?>">
                    <button class="btn btn-small btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<p class="muted"><?= count($appointments) ?> appointment(s) shown.</p>
<?php require __DIR__ . '/../includes/footer.php'; ?>
