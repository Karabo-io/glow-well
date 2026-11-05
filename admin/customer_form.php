<?php
    require_once __DIR__ . '/../includes/functions.php';
    require_login(['admin']);

    $pdo = db();
    $id = (int)($_GET['id'] ?? $_POST['customer_id'] ?? 0);
    $editing = $id > 0;
    $errors = [];
    $form = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'date_of_birth' => '', 'notes' => '', 'is_active' => '1'];
    $userId = 0;

    if ($editing) {
        $st = $pdo->prepare('SELECT c.*, u.email, u.is_active FROM customers c JOIN users u ON u.user_id = c.user_id WHERE c.customer_id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            flash('error', 'That customer does not exist.');
            redirect('admin/customers.php');
        }
        $userId = (int)$row['user_id'];
        foreach ($form as $k => $_) { $form[$k] = (string)($row[$k] ?? ''); }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach (['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'notes'] as $k) { $form[$k] = post($k); }
        $form['email'] = strtolower($form['email']);
        $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
        $password = $_POST['password'] ?? '';

        if (!valid_name($form['first_name'])) { $errors[] = 'Please enter a valid first name.'; }
        if (!valid_name($form['last_name']))  { $errors[] = 'Please enter a valid last name.'; }
        if (!valid_email($form['email']))     { $errors[] = 'Please enter a valid email address.'; }
        if (!valid_phone($form['phone']))     { $errors[] = 'Please enter a valid phone number.'; }
        if ($form['date_of_birth'] !== '' && (!valid_date($form['date_of_birth']) || $form['date_of_birth'] > date('Y-m-d'))) {
            $errors[] = 'Please enter a valid date of birth.';
        }
        if (mb_strlen($form['notes']) > 255) { $errors[] = 'Notes may not be longer than 255 characters.'; }
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
                $dob = $form['date_of_birth'] === '' ? null : $form['date_of_birth'];
                $notes = $form['notes'] === '' ? null : $form['notes'];
                if ($editing) {
                    $pdo->prepare('UPDATE users SET email = ?, is_active = ? WHERE user_id = ?')
                        ->execute([$form['email'], (int)$form['is_active'], $userId]);
                    if ($password !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                            ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                    }
                    $pdo->prepare('UPDATE customers SET first_name = ?, last_name = ?, phone = ?, date_of_birth = ?, notes = ? WHERE customer_id = ?')
                        ->execute([$form['first_name'], $form['last_name'], $form['phone'], $dob, $notes, $id]);
                } else {
                    $pdo->prepare('INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, ?, ?)')
                        ->execute([$form['email'], password_hash($password, PASSWORD_DEFAULT), 'customer', (int)$form['is_active']]);
                    $newUser = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO customers (user_id, first_name, last_name, phone, date_of_birth, notes) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$newUser, $form['first_name'], $form['last_name'], $form['phone'], $dob, $notes]);
                }
                $pdo->commit();
                flash('success', $editing ? 'Customer updated.' : 'Customer added.');
                redirect('admin/customers.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('customer_form: ' . $ex->getMessage());
                $errors[] = 'Could not save the customer. Please try again.';
            }
        }
    }

    $page_title = $editing ? 'Edit customer' : 'Add customer';
    require __DIR__ . '/../includes/header.php';
?>

<div class="card form-card">
    <h2><?= $editing ? 'Edit customer' : 'Add customer' ?></h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="customer_id" value="<?= (int)$id ?>">
        <div class="form-row">
            <div class="form-group"><label for="first_name">First name</label><input type="text" id="first_name" name="first_name" required maxlength="60" value="<?= e($form['first_name']) ?>"></div>
            <div class="form-group"><label for="last_name">Last name</label><input type="text" id="last_name" name="last_name" required maxlength="60" value="<?= e($form['last_name']) ?>"></div>
        </div>
        <div class="form-group"><label for="email">Email</label><input type="email" id="email" name="email" required value="<?= e($form['email']) ?>"></div>
        <div class="form-row">
            <div class="form-group"><label for="phone">Phone</label><input type="tel" id="phone" name="phone" required value="<?= e($form['phone']) ?>"></div>
            <div class="form-group"><label for="date_of_birth">Date of birth</label><input type="date" id="date_of_birth" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= e($form['date_of_birth']) ?>"></div>
        </div>
        <div class="form-group"><label for="password"><?= $editing ? 'New password (leave blank to keep current)' : 'Password' ?></label>
            <input type="password" id="password" name="password" <?= $editing ? '' : 'required' ?> minlength="8" data-password></div>
        <div class="form-group"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="255"><?= e($form['notes']) ?></textarea></div>
        <div class="form-group checkbox-row"><input type="checkbox" id="is_active" name="is_active" value="1" <?= $form['is_active'] === '1' ? 'checked' : '' ?>><label for="is_active">Account is active (can log in)</label></div>
        <div class="btn-row">
            <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add customer' ?></button>
            <a class="btn btn-outline" href="<?= e(url('admin/customers.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>