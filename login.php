<?php
    //Login with hashed-password check and simple brute-force lock-out.
    require_once __DIR__ . '/includes/functions.php'
    if(is_logged_in()) {
        redirect(home_for_role);
    }

    $pdo = db();
    $errors = [];
    $email = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $email = strtolower(post('email'));
        $password = $_POST['password'] ?? '';

        if (($_SESSION['lock_until'] ?? 0) > time()) {
            $errors[] = 'Too many failed attempts. Please wait a few minutes and try again.';
        } elseif ($email === '' || $password === '') {
            $errors[] = 'Please enter both your email and password.';
        } elseif (!valid_email($email)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $st = $pdo->prepare('SELECT user_id, password_hash, role, is_active FROM users WHERE email = ?');
            $st->execute([$email]);
            $user = $st->fetch();

            if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['name']    = load_user_name($pdo, (int)$user['user_id'], $user['role']);
                unset($_SESSION['fails'], $_SESSION['lock_until']);
                flash('success', 'Welcome back, ' . $_SESSION['name'] . '!');
                redirect(home_for_role());
            }
            $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= 5) {
                $_SESSION['lock_until'] = time() + 300;
                $_SESSION['fails'] = 0;
            }
            $errors[] = 'Incorrect email or password, or the account is inactive.';
        }
    }

    $page_title = 'Login';
    require __DIR__ . '/includes/header.php';
?>

<div class="card form-card">
    <h2>Log in</h2>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" data-validate>
        <?= csrf_field() ?>
        <div class="form-group"><label for="email">Email</label>
            <input type="email" id="email" name="email" required value="<?= e($email) ?>" autofocus></div>
        <div class="form-group"><label for="password">Password</label>
            <input type="password" id="password" name="password" required></div>
        <button class="btn" type="submit">Log in</button>
        <p class="muted">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
