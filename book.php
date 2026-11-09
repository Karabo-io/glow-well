<?php
    require_once __DIR__ . "/includes/functions.php";
    require_login(['customer']);

    $pdo = db();
    $customerId = customer_id_for_user($pdo, (int)$_SESSION['user_id']);

    $errors = [];
    $form = [
        "service_id" => (string)(int)($_GET['service_id'] ?? 0),
        "staff_id" => "",
        "appointment_date" => "",
        "appointment_time" => "",
        "notes" => "",
    ];

    if($form['service_id'] === "0"){
        $form['service_id'] = "";
    }

    if($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach($form as $k => $_){
            $form[$k] = post($k);
        }

        if (!ctype_digit($form['service_id']) || !ctype_digit($form['staff_id'])) {
            $errors[] = 'Please choose a service and a staff member.';
        } elseif ($form['appointment_date'] === '' || $form['appointment_time'] === '') {
            $errors[] = 'Please choose a date and one of the available times.';
        } else {
            [$ok, $msg, $apptId] = create_appointment(
                $pdo, $customerId, (int)$form['staff_id'], (int)$form['service_id'],
                $form['appointment_date'], $form['appointment_time'], 'pending', $form['notes']
            );
            if ($ok) {
                send_notification($pdo, $apptId, 'confirmation');
                flash('success', 'Your booking was received. We will confirm it shortly. A confirmation email has been sent.');
                redirect('my_appointments.php');
            }
            $errors[] = $msg;
        }
    }

    $services = $pdo->query(
        'SELECT s.service_id, s.service_name, s.duration_minutes, s.price, c.category_name
        FROM services s 
        JOIN service_categories c ON c.category_id = s.category_id
        WHERE s.is_active = 1 
        ORDER BY c.category_name, s.service_name'
    )->fetchAll();

    $page_title = 'Book an appointment';
    require __DIR__ . '/includes/header.php';
?>

<div class="card form-card form-wide">
    <h2>Book an appointment</h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" id="bookingForm" data-api="<?= e(url('api')) ?>"
          data-staff="<?= e($form['staff_id']) ?>" data-time="<?= e($form['appointment_time']) ?>">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="service_id">1. Service</label>
                <select id="service_id" name="service_id" required>
                    <option value="">Choose a service</option>
                    <?php $cat = null; foreach ($services as $s): ?>
                        <?php if ($cat !== $s['category_name']): ?>
                            <?php if ($cat !== null): ?></optgroup><?php endif; ?>
                            <optgroup label="<?= e($s['category_name']) ?>">
                            <?php $cat = $s['category_name']; ?>
                        <?php endif; ?>
                        <option value="<?= (int)$s['service_id'] ?>" <?= $form['service_id'] === (string)$s['service_id'] ? 'selected' : '' ?>>
                            <?= e($s['service_name']) ?> - <?= e(money($s['price'])) ?> (<?= (int)$s['duration_minutes'] ?> min)
                        </option>
                    <?php endforeach; ?>
                    <?php if ($cat !== null): ?></optgroup><?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="staff_id">2. Staff member</label>
                <select id="staff_id" name="staff_id" required><option value="">Select a service first</option></select>
            </div>
        </div>
        <div class="form-group">
            <label for="appointment_date">3. Date</label>
            <input type="date" id="appointment_date" name="appointment_date" required
                   min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+180 days')) ?>"
                   value="<?= e($form['appointment_date']) ?>">
        </div>
        <div class="form-group">
            <label>4. Available times</label>
            <input type="hidden" id="appointment_time" name="appointment_time" value="">
            <div class="slots" id="slots"><span class="muted">Choose a service, staff member and date to see available times.</span></div>
        </div>
        <div class="form-group">
            <label for="notes">Notes for the therapist (optional)</label>
            <textarea id="notes" name="notes" rows="3" maxlength="255"><?= e($form['notes']) ?></textarea>
        </div>
        <button class="btn" type="submit">Confirm booking</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
