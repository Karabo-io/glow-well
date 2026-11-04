<?php
    require_once __DIR__ . '/includes/functions.php';
    require_login(['customer']);

    $pdo = db();
    $customerId = customer_id_for_user($pdo, (int)$_SESSION['user_id']);
    $id =(int)($_GET[id] ?? $_POST['appointment_id'] ?? 0);

    $st = $pdo->prepare(
        "SELECT a.*, sv.service_name, CONCAT(s.first_name, ' ', s.last_name) AS staff_name
        FROM appointments a
        JOIN services sv ON sv.service_id = a.service_id
        JOIN staff s ON s.staff_id = a.staff_id
        WHERE a.appointment_id = ? AND a.customer_id = ?"
    );

    $st->execute([$id, $customerId]);
    $appt = $st->fetch();

    if (!$appt) {
        flash('error', 'That appointment could not be found.');
        redirect('my_appointments.php');
    }
    if (!in_array($appt['status'], ['pending', 'confirmed'], true) || $appt['appointment_date'] < date('Y-m-d')) {
        flash('error', 'This appointment can no longer be rescheduled.');
        redirect('my_appointments.php');
    }

    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $date = post('appointment_date');
        $time = post('appointment_time');
        if ($date === '' || $time === '') {
            $errors[] = 'Please choose a new date and one of the available times.';
        } else {
            [$ok, $msg] = reschedule_appointment($pdo, $id, $date, $time);
            if ($ok) {
                $pdo->prepare("UPDATE appointments SET status = 'pending' WHERE appointment_id = ?")->execute([$id]);
                send_notification($pdo, $id, 'rescheduled');
                flash('success', 'Your appointment was moved. It will be re-confirmed by the clinic.');
                redirect('my_appointments.php');
            }
            $errors[] = $msg;
        }
    }

    $page_title = 'Reschedule appointment';
    require __DIR__ . '/includes/header.php';
?>

<div class="card form-card">
    <h2>Reschedule appointment</h2>
    <p><strong><?= e($appt['service_name']) ?></strong> with <?= e($appt['staff_name']) ?><br>
       <span class="muted">Currently: <?= e(fmt_date($appt['appointment_date'])) ?> at <?= e(fmt_time($appt['start_time'])) ?></span></p>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" id="bookingForm" data-api="<?= e(url('api')) ?>" data-exclude="<?= (int)$id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= (int)$id ?>">
        <input type="hidden" id="service_id" value="<?= (int)$appt['service_id'] ?>">
        <input type="hidden" id="staff_id" value="<?= (int)$appt['staff_id'] ?>">
        <div class="form-group">
            <label for="appointment_date">New date</label>
            <input type="date" id="appointment_date" name="appointment_date" required
                   min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+180 days')) ?>"
                   value="<?= e($appt['appointment_date']) ?>">
        </div>
        <div class="form-group">
            <label>Available times</label>
            <input type="hidden" id="appointment_time" name="appointment_time" value="">
            <div class="slots" id="slots"></div>
        </div>
        <div class="btn-row">
            <button class="btn" type="submit">Save new time</button>
            <a class="btn btn-outline" href="<?= e(url('my_appointments.php')) ?>">Back</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
