<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);

    $pdo = db();
    $statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];
    $id = (int)($_GET['id'] ?? $_POST['appointment_id'] ?? 0);
    $editing = $id > 0;
    $errors = [];

    $current = null;
    if ($editing) {
        $st = $pdo->prepare('SELECT * FROM appointments WHERE appointment_id = ?');
        $st->execute([$id]);
        $current = $st->fetch();
        if (!$current) {
            flash('error', 'That appointment does not exist.');
            redirect('admin/appointments.php');
        }
    }

    $form = [
        'customer_id' => $current['customer_id'] ?? "",
        'service_id'  => $current['service_id'] ?? "",
        'staff_id'    => $current['staff_id'] ?? "",
        'appointment_date' => $current['appointment_date'] ?? '',
        'appointment_time' => isset($current['start_time']) ? fmt_time($current['start_time']) : "",
        'status' => $current['status'] ?? 'confirmed',
        'notes'  => $current['notes'] ?? "",
        'pay_method' => "",
    ];

    $existingPayment = null;
    if ($editing && ($_GET['status'] ?? '') === 'completed') { $form['status'] = 'completed'; }
    if ($editing) {
        $st = $pdo->prepare('SELECT method, amount FROM payments WHERE appointment_id = ?');
        $st->execute([$id]);
        $existingPayment = $st->fetch() ?: null;
        $form['pay_method'] = $existingPayment['method'] ?? '';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach ($form as $k => $_) { $form[$k] = post($k); }
        $timeBlank = false;
        if ($editing) {
            // customer and service are fixed once an appointment exists
            $form['customer_id'] = (string)$current['customer_id'];
            $form['service_id']  = (string)$current['service_id'];
            if ($form['staff_id'] === '') { $form['staff_id'] = (string)$current['staff_id']; }
            if ($form['appointment_date'] === '') { $form['appointment_date'] = $current['appointment_date']; }
            if ($form['appointment_time'] === '') {
                $form['appointment_time'] = fmt_time($current['start_time']);
                $timeBlank = true;   // no new time was picked
            }
        }

        if (!in_array($form['status'], $statuses, true)) {
            $errors[] = 'Please choose a valid status.';
        }
        if ($form['pay_method'] !== '' && !in_array($form['pay_method'], ['cash', 'card', 'eft'], true)) {
            $errors[] = 'Please choose a valid payment method.';
        }
        if ($form['status'] === 'completed' && $form['pay_method'] === '' && !$existingPayment) {
            $errors[] = 'Please record how the customer paid before marking the appointment completed.';
        }
        if (mb_strlen($form['notes']) > 255) { $errors[] = 'Notes may not be longer than 255 characters.'; }

        if (!$errors && !$editing) {
            if (!ctype_digit($form['customer_id']) || !ctype_digit($form['service_id']) || !ctype_digit($form['staff_id'])) {
                $errors[] = 'Please choose a customer, a service and a staff member.';
            } else {
                [$ok, $msg, $newId] = create_appointment(
                    $pdo, (int)$form['customer_id'], (int)$form['staff_id'], (int)$form['service_id'],
                    $form['appointment_date'], $form['appointment_time'], $form['status'], $form['notes']
                );
                if ($ok) {
                    if ($form['status'] === 'completed') {
                        $pdo->prepare('INSERT INTO payments (appointment_id, amount, method) SELECT appointment_id, price_charged, ? FROM appointments WHERE appointment_id = ?')
                            ->execute([$form['pay_method'], $newId]);
                    }
                    send_notification($pdo, $newId, 'confirmation');
                    flash('success', 'Appointment created and the customer was notified.');
                    redirect('admin/appointments.php');
                }
                $errors[] = $msg;
            }
        } elseif (!$errors && $editing) {
            $moved = $form['appointment_date'] !== $current['appointment_date']
                || $form['appointment_time'] !== fmt_time($current['start_time'])
                || (int)$form['staff_id'] !== (int)$current['staff_id'];
            if ($moved) {
                if ($timeBlank) {
                    $errors[] = 'Please pick one of the available times for the new date or therapist.';
                } else {
                    [$ok, $msg] = reschedule_appointment($pdo, $id, $form['appointment_date'], $form['appointment_time'], (int)$form['staff_id']);
                    if (!$ok) { $errors[] = $msg; }
                }
            }
            if (!$errors) {
                try {
                    $pdo->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?')
                        ->execute([$form['status'], $form['notes'] === '' ? null : $form['notes'], $id]);
                    if ($form['pay_method'] !== '') {
                        $pdo->prepare(
                            'INSERT INTO payments (appointment_id, amount, method)
                            SELECT appointment_id, price_charged, ? FROM appointments WHERE appointment_id = ?
                            ON DUPLICATE KEY UPDATE method = VALUES(method)'
                        )->execute([$form['pay_method'], $id]);
                    }
                    if ($moved) { send_notification($pdo, $id, 'rescheduled'); }
                    if ($form['status'] === 'cancelled' && $current['status'] !== 'cancelled') { send_notification($pdo, $id, 'cancellation'); }
                    flash('success', 'Appointment updated.');
                    redirect('admin/appointments.php');
                } catch (PDOException $ex) {
                    $errors[] = $ex->getCode() === '23000'
                        ? 'That slot is already booked, so the status could not be changed.'
                        : 'Could not save the changes. Please try again.';
                    error_log('appointment_form: ' . $ex->getMessage());
                }
            }
        }
    }

    $customers = $pdo->query("SELECT customer_id, CONCAT(first_name, ' ', last_name) AS name, phone FROM customers ORDER BY first_name, last_name")->fetchAll();
    $services = $pdo->query('SELECT service_id, service_name, price, duration_minutes FROM services WHERE is_active = 1 ORDER BY service_name')->fetchAll();

    $page_title = $editing ? 'Edit appointment' : 'New appointment';
    require __DIR__ . '/../includes/header.php';
?>

<div class="card form-card form-wide">
    <h2><?= $editing ? 'Edit appointment #' . (int)$id : 'New appointment' ?></h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" id="bookingForm" data-api="<?= e(url('api')) ?>"
          data-exclude="<?= (int)$id ?>" data-staff="<?= e($form['staff_id']) ?>" data-time="<?= e($form['appointment_time']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$id ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="customer_id">Customer</label>
                <?php if ($editing): ?>
                    <select id="customer_id" disabled>
                        <?php foreach ($customers as $c): if ((int)$c['customer_id'] === (int)$form['customer_id']): ?>
                            <option><?= e($c['name']) ?></option><?php endif; endforeach; ?>
                    </select>
                <?php else: ?>
                    <select id="customer_id" name="customer_id" required>
                        <option value="">Choose a customer</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['customer_id'] ?>" <?= (string)$form['customer_id'] === (string)$c['customer_id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['phone']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="service_id">Service</label>
                <?php if ($editing): ?>
                    <input type="hidden" id="service_id" value="<?= (int)$form['service_id'] ?>">
                    <select disabled><?php foreach ($services as $s): if ((int)$s['service_id'] === (int)$form['service_id']): ?>
                        <option><?= e($s['service_name']) ?></option><?php endif; endforeach; ?></select>
                <?php else: ?>
                    <select id="service_id" name="service_id" required>
                        <option value="">Choose a service</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int)$s['service_id'] ?>" <?= (string)$form['service_id'] === (string)$s['service_id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?> - <?= e(money($s['price'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="staff_id">Therapist</label>
                <select id="staff_id" name="staff_id" required><option value="">Select a service first</option></select>
            </div>
            <div class="form-group">
                <label for="appointment_date">Date</label>
                <input type="date" id="appointment_date" name="appointment_date" required value="<?= e($form['appointment_date']) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Available times <?= $editing ? '(current time is pre-selected)' : '' ?></label>
            <input type="hidden" id="appointment_time" name="appointment_time" value="<?= e($form['appointment_time']) ?>">
            <div class="slots" id="slots"></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pay_method">Payment method (required when completed)</label>
                <select id="pay_method" name="pay_method">
                    <option value="">Not paid yet</option>
                    <?php foreach (['cash' => 'Cash', 'card' => 'Card', 'eft' => 'EFT'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $form['pay_method'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($existingPayment): ?><div class="hint">Recorded payment: <?= e(money($existingPayment['amount'])) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3" maxlength="255"><?= e($form['notes']) ?></textarea>
        </div>
        <div class="btn-row">
            <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create appointment' ?></button>
            <a class="btn btn-outline" href="<?= e(url('admin/appointments.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
