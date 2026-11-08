<?php
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/../config/db.php';

    if(is_logged_in()) {
        redirect(home_for_role());
    }
    $pdo = db();
    $errors = [];
    $old = ["first_name" => "", "last_name" => "", "email" => "", "phone" => "", "date_of_birth" => ""];

    if($_SERVER['REQUEST_METHOD'] === "POST") {
        csrf_check();
        foreach($old as $k => $_){
            $old[$k] = post($k);
        }

        $password = $_POST['password'] ?? "";
        $confirm = $_POST['confirm_password'] ?? "";
        $old["email"] = strtolower($old["email"]);

        if(!valid_name($old["first_name"])) { $errors[] = 'Please enter a valid first name (letters only).'; }
        if(!valid_name($old["last_name"])) {$errors[] =  'Please enter a valid last name (letters only).'; }
        if (!valid_email($old["email"])) { $errors[] = 'Please enter a valid email address.'; }
        if (!valid_phone($old["phone"])) { $errors[] = 'Please enter a valid phone number.'; }
        if($old["date_of_birth"] !==  "" && (!valid_date($old["date_of_birth"]) || $old["date_of_birth"] > date('Y-m-d'))) {
            $errors[] = 'Please enter a valid date of birth.' ;
        }
        if(!valid_password($password)) {$errors[] = 'Password must be at least 8 characters and contain a letter and a number.'; }
        if($password !== $confirm) {$errors[] = 'The two passowords do not match.'; }

        if(!$errors) {
            $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
            $st->execute([$old['email']]);
            if($st->fetchColumn()) {
                $errors[] = 'An account with that email already exists. Try logging in instead.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)')
                    ->execute([$old['email'], password_hash($password, PASSWORD_DEFAULT), 'customer']);
                $uid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO customers (user_id, first_name, last_name, phone, date_of_birth) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$uid, $old['first_name'], $old['last_name'], $old['phone'],
                            $old['date_of_birth'] === '' ? null : $old['date_of_birth']]);
                $pdo->commit();
                flash('success', 'Account created! You can now log in.');
                redirect('login.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('register: ' . $ex->getMessage());
                $errors[] = 'We could not create your account. Please try again.';
            }
        }

    }

    $page_title = 'Register';
    require __DIR__ . '/includes/header.php';
?>

<div class="card form-card">
    <h2>Create your account</h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <form method="post" data-validate>
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group"><label for="first_name">First name</label>
                <input type="text" id="first_name" name="first_name" data-label="your first name" required maxlength="60" value="<?= e($old['first_name']) ?>"></div>
            <div class="form-group"><label for="last_name">Last name</label>
                <input type="text" id="last_name" name="last_name" data-label="your last name" required maxlength="60" value="<?= e($old['last_name']) ?>"></div>
        </div>
        <div class="form-group"><label for="email">Email</label>
            <input type="email" id="email" name="email" required maxlength="150" value="<?= e($old['email']) ?>"></div>
        <div class="form-row">
            <div class="form-group"><label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" required value="<?= e($old['phone']) ?>" placeholder="e.g. 0821234567"></div>
            <div class="form-group"><label for="date_of_birth">Date of birth (optional)</label>
                <input type="date" id="date_of_birth" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= e($old['date_of_birth']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8" data-password>
                <div class="hint">At least 8 characters, with a letter and a number.</div></div>
            <div class="form-group"><label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password" required data-match="password" data-label="the password again"></div>
        </div>
        <button class="btn" type="submit">Register</button>
        <p class="muted">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
