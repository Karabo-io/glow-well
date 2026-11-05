<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);

    $pdo = db();
    $id = (int)($_GET['id'] ?? $_POST['staff_id'] ?? 0);
    $editing = $id > 0;
    $errors = [];
    $form = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'job_title' => '', 'hire_date' => date('Y-m-d'), 'is_active' => '1'];
    $chosenServices = [];
    $sched = [];   // day => ['on' => bool, 'start' => 'HH:MM', 'end' => 'HH:MM']

    for ($d = 1; $d <= 7; $d++) {
         $sched[$d] = ['on' => false, 'start' => '08:00', 'end' => '16:00']; 
    }
    
    $userId = 0;

    if ($editing) {
        $st = $pdo->prepare('SELECT s.*, u.email, u.is_active FROM staff s JOIN users u ON u.user_id = s.user_id WHERE s.staff_id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            flash('error', 'That staff member does not exist.');
            redirect('admin/staff.php');
        }
        $userId = (int)$row['user_id'];
        foreach ($form as $k => $_) { $form[$k] = (string)($row[$k] ?? ''); }
        $st = $pdo->prepare('SELECT service_id FROM staff_services WHERE staff_id = ?');
        $st->execute([$id]);
        $chosenServices = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare('SELECT day_of_week, start_time, end_time FROM staff_schedules WHERE staff_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            $sched[(int)$r['day_of_week']] = ['on' => true, 'start' => fmt_time($r['start_time']), 'end' => fmt_time($r['end_time'])];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach (['first_name', 'last_name', 'email', 'phone', 'job_title', 'hire_date'] as $k) { $form[$k] = post($k); }
        $form['email'] = strtolower($form['email']);
        $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
        $password = $_POST['password'] ?? '';
        $chosenServices = array_values(array_unique(array_map('intval', (array)($_POST['services'] ?? []))));

        for ($d = 1; $d <= 7; $d++) {
            $in = $_POST['sched'][$d] ?? [];
            $sched[$d] = ['on' => !empty($in['on']), 'start' => trim((string)($in['start'] ?? '')), 'end' => trim((string)($in['end'] ?? ''))];
            if ($sched[$d]['on']) {
                if (!valid_time($sched[$d]['start']) || !valid_time($sched[$d]['end'])) {
                    $errors[] = day_name($d) . ': please enter valid start and end times.';
                } elseif ($sched[$d]['end'] <= $sched[$d]['start']) {
                    $errors[] = day_name($d) . ': the end time must be after the start time.';
                }
            }
        }

        if (!valid_name($form['first_name'])) { $errors[] = 'Please enter a valid first name.'; }
        if (!valid_name($form['last_name']))  { $errors[] = 'Please enter a valid last name.'; }
        if (!valid_email($form['email']))     { $errors[] = 'Please enter a valid email address.'; }
        if (!valid_phone($form['phone']))     { $errors[] = 'Please enter a valid phone number.'; }
        if ($form['job_title'] === '' || mb_strlen($form['job_title']) > 80) { $errors[] = 'Please enter a job title (up to 80 characters).'; }
        if (!valid_date($form['hire_date']))  { $errors[] = 'Please enter a valid hire date.'; }
        if (!$editing && !valid_password($password)) { $errors[] = 'Please set a password of at least 8 characters with a letter and a number.'; }
        if ($editing && $password !== '' && !valid_password($password)) { $errors[] = 'The new password must be at least 8 characters with a letter and a number.'; }

        if (!$errors) {
            $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ?');
            $st->execute([$form['email'], $userId]);
            if ($st->fetchColumn()) { $errors[] = 'Another account already uses that email address.'; }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($editing) {
                    $pdo->prepare('UPDATE users SET email = ?, is_active = ? WHERE user_id = ?')->execute([$form['email'], (int)$form['is_active'], $userId]);
                    if ($password !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                    }
                    $pdo->prepare('UPDATE staff SET first_name = ?, last_name = ?, phone = ?, job_title = ?, hire_date = ? WHERE staff_id = ?')
                        ->execute([$form['first_name'], $form['last_name'], $form['phone'], $form['job_title'], $form['hire_date'], $id]);
                    $staffId = $id;
                } else {
                    $pdo->prepare('INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, ?, ?)')
                        ->execute([$form['email'], password_hash($password, PASSWORD_DEFAULT), 'staff', (int)$form['is_active']]);
                    $newUser = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO staff (user_id, first_name, last_name, phone, job_title, hire_date) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$newUser, $form['first_name'], $form['last_name'], $form['phone'], $form['job_title'], $form['hire_date']]);
                    $staffId = (int)$pdo->lastInsertId();
                }

                // Replace the service list and weekly schedule
                $pdo->prepare('DELETE FROM staff_services WHERE staff_id = ?')->execute([$staffId]);
                $insSvc = $pdo->prepare('INSERT INTO staff_services (staff_id, service_id) SELECT ?, service_id FROM services WHERE service_id = ?');
                foreach ($chosenServices as $sv) { $insSvc->execute([$staffId, $sv]); }

                $pdo->prepare('DELETE FROM staff_schedules WHERE staff_id = ?')->execute([$staffId]);
                $insSch = $pdo->prepare('INSERT INTO staff_schedules (staff_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)');
                foreach ($sched as $d => $row) {
                    if ($row['on']) { $insSch->execute([$staffId, $d, $row['start'], $row['end']]); }
                }
                $pdo->commit();
                flash('success', $editing ? 'Staff member updated.' : 'Staff member added.');
                redirect('admin/staff.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('staff_form: ' . $ex->getMessage());
                $errors[] = 'Could not save the staff member. Please try again.';
            }
        }
    }

    $allServices = $pdo->query('SELECT service_id, service_name FROM services ORDER BY service_name')->fetchAll();

    $page_title = $editing ? 'Edit staff member' : 'Add staff member';
    require __DIR__ . '/../includes/header.php';
?>
<div class="card form-card form-wide">
    <h2><?= $editing ? 'Edit staff member' : 'Add staff member' ?></h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="staff_id" value="<?= (int)$id ?>">
        <div class="form-row">
            <div class="form-group"><label for="first_name">First name</label><input type="text" id="first_name" name="first_name" required maxlength="60" value="<?= e($form['first_name']) ?>"></div>
            <div class="form-group"><label for="last_name">Last name</label><input type="text" id="last_name" name="last_name" required maxlength="60" value="<?= e($form['last_name']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="email">Email (login)</label><input type="email" id="email" name="email" required value="<?= e($form['email']) ?>"></div>
            <div class="form-group"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" required value="<?= e($form['phone']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="job_title">Job title</label><input type="text" id="job_title" name="job_title" required maxlength="80" value="<?= e($form['job_title']) ?>"></div>
            <div class="form-group"><label for="hire_date">Hire date</label><input type="date" id="hire_date" name="hire_date" required value="<?= e($form['hire_date']) ?>"></div>
        </div>
        <div class="form-group"><label for="password"><?= $editing ? 'New password (leave blank to keep current)' : 'Password' ?></label>
            <input type="password" id="password" name="password" <?= $editing ? '' : 'required' ?> minlength="8" data-password></div>
        <div class="form-group checkbox-row"><input type="checkbox" id="is_active" name="is_active" value="1" <?= $form['is_active'] === '1' ? 'checked' : '' ?>><label for="is_active">Account is active</label></div>

        <h3>Services offered</h3>
        <div class="check-grid form-group">
            <?php foreach ($allServices as $sv): ?>
                <div class="checkbox-row"><input type="checkbox" id="svc<?= (int)$sv['service_id'] ?>" name="services[]" value="<?= (int)$sv['service_id'] ?>" <?= in_array((int)$sv['service_id'], $chosenServices, true) ? 'checked' : '' ?>>
                    <label for="svc<?= (int)$sv['service_id'] ?>"><?= e($sv['service_name']) ?></label></div>
            <?php endforeach; ?>
        </div>

        <h3>Weekly working hours</h3>
        <div class="table-wrap"><table class="schedule-table">
            <thead><tr><th>Day</th><th>Working</th><th>Start</th><th>End</th></tr></thead>
            <tbody>
            <?php for ($d = 1; $d <= 7; $d++): ?>
                <tr>
                    <td><?= e(day_name($d)) ?></td>
                    <td><input type="checkbox" name="sched[<?= $d ?>][on]" value="1" aria-label="Works on <?= e(day_name($d)) ?>" <?= $sched[$d]['on'] ? 'checked' : '' ?>></td>
                    <td><input type="time" name="sched[<?= $d ?>][start]" value="<?= e($sched[$d]['start']) ?>"></td>
                    <td><input type="time" name="sched[<?= $d ?>][end]" value="<?= e($sched[$d]['end']) ?>"></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table></div>
        <div class="btn-row">
            <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add staff member' ?></button>
            <a class="btn btn-outline" href="<?= e(url('admin/staff.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
